<?php

namespace Tests\Feature;

use App\Actions\Payments\ApplyConfirmedPaymentLifecycle;
use App\Actions\Payments\ConfirmPayment;
use App\Exceptions\Payments\PaymentConfirmationException;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class PaymentConfirmationActionTest extends AuthorizationTestCase
{
    use RefreshDatabase;

    public function test_web_route_confirms_pending_payment_for_each_allowed_invoice_state(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        foreach (['issued', 'partially_paid'] as $status) {
            [$invoice] = $this->invoiceFixture($status);
            $payment = $this->pendingPayment($invoice, '40.00');

            $this->patch(route('payments.confirm', $payment))
                ->assertRedirect(route('home'))
                ->assertSessionHas('success', 'Платёж подтверждён. Сумма оплаты и статус инвойса пересчитаны.')
                ->assertSessionDoesntHaveErrors();

            $this->assertSame('confirmed', $payment->fresh()->status);
            $this->assertSame('partially_paid', $invoice->fresh()->status);
        }
    }

    public function test_paid_invoice_accepts_confirmation_and_preserves_overpayment_credit_behavior(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        [$invoice, [$line]] = $this->invoiceFixture('paid');
        $existing = $this->rawPayment($invoice, 'confirmed', '100.00');
        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $payment = $this->pendingPayment($invoice, '30.00');

        $this->patch(route('payments.confirm', $payment))->assertSessionDoesntHaveErrors();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $existing->id,
            'invoice_line_id' => $line->id,
            'amount' => '100.00',
        ]);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'amount' => '30.00',
        ]);
        $this->assertSame($invoice->id, $payment->fresh()->invoice_id);
    }

    public function test_pending_overpayment_is_confirmed_with_capped_allocation_and_one_linked_top_up(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        [$invoice, [$line]] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '130.00');

        $this->patch(route('payments.confirm', $payment))->assertSessionDoesntHaveErrors();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_line_id' => $line->id,
            'amount' => '100.00',
        ]);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_disallowed_invoice_states_are_business_conflicts_without_side_effects(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        foreach (['draft', 'cancelled'] as $status) {
            [$invoice] = $this->invoiceFixture($status);
            $payment = $this->pendingPayment($invoice, '25.00');

            $this->from(route('home'))->patch(route('payments.confirm', $payment))
                ->assertRedirect(route('home'))
                ->assertSessionHasErrors('payment_confirm');

            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame($status, $invoice->fresh()->status);
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_non_pending_payment_states_are_business_conflicts_even_for_administrator(): void
    {
        $administrator = $this->actingAsPermissions([]);
        $administrator->assignRole('administrator');

        foreach (['confirmed', 'cancelled'] as $status) {
            [$invoice] = $this->invoiceFixture('issued');
            $payment = $this->rawPayment($invoice, $status, '25.00');

            $this->patch(route('payments.confirm', $payment))
                ->assertSessionHasErrors('payment_confirm');

            $this->assertSame($status, $payment->fresh()->status);
            $this->assertSame('issued', $invoice->fresh()->status);
        }

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_invoice_relationship_mismatch_is_rejected_from_locked_rows(): void
    {
        [$routeInvoice] = $this->invoiceFixture('issued');
        [$currentInvoice] = $this->invoiceFixture('issued', $routeInvoice->company_id);
        $payment = $this->pendingPayment($routeInvoice, '25.00');
        DB::table('payments')->where('id', $payment->id)->update(['invoice_id' => $currentInvoice->id]);

        $this->expectBusinessConflict(fn () => app(ConfirmPayment::class)->execute($payment));

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $routeInvoice->fresh()->status);
        $this->assertSame('issued', $currentInvoice->fresh()->status);
        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_company_mismatch_is_rejected_without_company_relation_query_or_mutation(): void
    {
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '25.00');
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other company']);
        DB::table('payments')->where('id', $payment->id)->update(['company_id' => $otherCompanyId]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->captureBusinessConflict(fn () => app(ConfirmPayment::class)->execute($payment))
        );

        $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_invalid_persisted_amount_matrix_is_rejected_before_save(): void
    {
        foreach (['0', '-0.01'] as $amount) {
            [$invoice] = $this->invoiceFixture('issued');
            $payment = $this->pendingPayment($invoice, $amount);

            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->captureBusinessConflict(fn () => app(ConfirmPayment::class)->execute($payment))
            );

            $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($capture['records']));
            $this->assertSame(2, DomainQueryRecorder::count($capture['records']));
            $this->assertSame('pending', $payment->fresh()->status, "Amount {$amount} must remain pending.");
            $this->assertSame('issued', $invoice->fresh()->status);
        }

        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_shared_minor_unit_parser_rejects_noncanonical_legacy_amounts(): void
    {
        $parser = app(InvoicePaymentAvailabilityService::class);

        foreach (['malformed', '1e2', '1.001', '+1.00', '1,00', 'NaN', 'INF', '', ' '] as $amount) {
            try {
                $parser->toMinorUnits($amount);
                $this->fail("Amount {$amount} must be rejected.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_action_maps_parser_failure_and_decimal_overflow_to_business_conflicts(): void
    {
        [$invalidInvoice] = $this->invoiceFixture('issued');
        $invalidPayment = $this->pendingPayment($invalidInvoice, '1.00');
        $invalidParser = Mockery::mock(InvoicePaymentAvailabilityService::class);
        $invalidParser->shouldReceive('toMinorUnits')->once()->andThrow(new LogicException('internal parser detail'));
        $invalidAction = new ConfirmPayment(
            $invalidParser,
            app(ApplyConfirmedPaymentLifecycle::class)
        );

        $this->expectBusinessConflict(fn () => $invalidAction->execute($invalidPayment));

        [$overflowInvoice] = $this->invoiceFixture('issued');
        $overflowPayment = $this->pendingPayment($overflowInvoice, '1.00');
        $overflowParser = Mockery::mock(InvoicePaymentAvailabilityService::class);
        $overflowParser->shouldReceive('toMinorUnits')->once()->andReturn(10_000_000_000);
        $overflowAction = new ConfirmPayment(
            $overflowParser,
            app(ApplyConfirmedPaymentLifecycle::class)
        );

        $this->expectBusinessConflict(fn () => $overflowAction->execute($overflowPayment));
        $this->assertSame('pending', $invalidPayment->fresh()->status);
        $this->assertSame('pending', $overflowPayment->fresh()->status);
        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_decimal_boundaries_use_shared_minor_unit_semantics(): void
    {
        foreach (['0.01', '1', '1.2', '99999999.99'] as $amount) {
            [$invoice] = $this->invoiceFixture('issued');
            $invoice->forceFill(['total_amount' => '99999999.99'])->saveQuietly();
            $invoice->lines()->update(['amount' => '99999999.99']);
            $payment = $this->pendingPayment($invoice, $amount);

            $confirmed = app(ConfirmPayment::class)->execute($payment);

            $this->assertSame('confirmed', $confirmed->status);
            $this->assertSame('confirmed', $payment->fresh()->status);
        }
    }

    public function test_action_requeries_stale_payment_and_invoice_lifecycle_state(): void
    {
        [$paymentInvoice] = $this->invoiceFixture('issued');
        $stalePayment = $this->pendingPayment($paymentInvoice, '25.00');
        DB::table('payments')->where('id', $stalePayment->id)->update(['status' => 'cancelled']);

        $this->expectBusinessConflict(fn () => app(ConfirmPayment::class)->execute($stalePayment));
        $this->assertSame('cancelled', $stalePayment->fresh()->status);

        [$staleInvoice] = $this->invoiceFixture('issued');
        $invoicePayment = $this->pendingPayment($staleInvoice, '25.00');
        DB::table('invoices')->where('id', $staleInvoice->id)->update(['status' => 'cancelled']);

        $this->expectBusinessConflict(fn () => app(ConfirmPayment::class)->execute($invoicePayment));
        $this->assertSame('pending', $invoicePayment->fresh()->status);
        $this->assertSame('cancelled', $staleInvoice->fresh()->status);
        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_repeated_confirmation_is_a_conflict_without_duplicate_credit_or_allocations(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '130.00');

        $this->patch(route('payments.confirm', $payment))->assertSessionDoesntHaveErrors();
        $allocation = PaymentAllocation::query()->sole()->getRawOriginal();
        $balance = CreditBalance::query()->sole()->getRawOriginal();

        $this->patch(route('payments.confirm', $payment))->assertSessionHasErrors('payment_confirm');

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($allocation, PaymentAllocation::query()->sole()->getRawOriginal());
        $this->assertSame($balance, CreditBalance::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_allocation_writer_failure_propagates_and_rolls_back_model_event_effects(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '130.00');
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('writer-failure'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
        $this->withoutExceptionHandling();

        try {
            $this->patch(route('payments.confirm', $payment));
            $this->fail('Allocation writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('writer-failure', $exception->getMessage());
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertFinancialSideEffectsAbsent();
    }

    public function test_confirmation_uses_quiet_save_and_explicit_lifecycle(): void
    {
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '130.00');
        $savedEventRan = false;
        Payment::saved(function (Payment $saved) use ($payment, &$savedEventRan): void {
            if ($saved->is($payment) && $saved->status === 'confirmed') {
                $savedEventRan = true;
            }
        });

        try {
            app(ConfirmPayment::class)->execute($payment);
        } finally {
            Payment::flushEventListeners();
        }

        $this->assertFalse($savedEventRan);
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_policy_denial_happens_after_binding_and_before_financial_queries(): void
    {
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->pendingPayment($invoice, '25.00');
        $this->actingAsPermissions([]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->patch(route('payments.confirm', $payment))
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_business_conflict_queries_stop_after_binding_and_two_locks(): void
    {
        [$invoice] = $this->invoiceFixture('issued');
        $payment = $this->rawPayment($invoice, 'confirmed', '25.00');
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->patch(route('payments.confirm', $payment))
        );

        $capture['result']->assertSessionHasErrors('payment_confirm');
        $this->assertSame(['payments', 'invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));
    }

    public function test_success_query_count_is_bounded_for_one_and_many_invoice_lines(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $counts = [];

        foreach ([1, 6] as $lineCount) {
            [$invoice] = $this->invoiceFixture('issued', null, array_fill(0, $lineCount, '10.00'));
            $payment = $this->pendingPayment($invoice, '5.00');
            $capture = (new DomainQueryRecorder)->capture(
                fn () => app(ConfirmPayment::class)->execute($payment)
            );

            $this->assertSame('confirmed', $capture['result']->status);
            $tables = DomainQueryRecorder::tables($capture['records']);
            $this->assertContains('invoices', $tables);
            $this->assertContains('invoice_lines', $tables);
            $this->assertContains('payments', $tables);
            $this->assertContains('payment_allocations', $tables);
            $this->assertNotContains('companies', $tables);
            $this->assertNotContains('credit_balances', $tables);
            $counts[] = DomainQueryRecorder::count($capture['records']);
            $this->assertLessThanOrEqual(12, end($counts));
        }

        $this->assertSame($counts[0], $counts[1]);
    }

    /**
     * @param  list<string>  $lineAmounts
     * @return array{Invoice, list<InvoiceLine>}
     */
    private function invoiceFixture(
        string $status,
        ?int $companyId = null,
        array $lineAmounts = ['100.00']
    ): array {
        $companyId ??= DB::table('companies')->insertGetId(['name' => 'Confirmation '.uniqid()]);
        $totalMinor = array_sum(array_map(
            fn (string $amount): int => app(InvoicePaymentAvailabilityService::class)->toMinorUnits($amount),
            $lineAmounts
        ));
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'invoice_number' => 'CONFIRM-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => app(InvoicePaymentAvailabilityService::class)->fromMinorUnits($totalMinor),
            'status' => $status,
        ]);
        $lines = [];

        foreach ($lineAmounts as $index => $amount) {
            $lines[] = $invoice->lines()->create([
                'description' => 'Confirmation line '.($index + 1),
                'amount' => $amount,
            ]);
        }

        return [$invoice, $lines];
    }

    private function pendingPayment(Invoice $invoice, string $amount): Payment
    {
        return $this->rawPayment($invoice, 'pending', $amount);
    }

    private function rawPayment(Invoice $invoice, string $status, string $amount): Payment
    {
        $id = DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-05',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Payment::query()->findOrFail($id);
    }

    private function expectBusinessConflict(callable $callback): void
    {
        $this->captureBusinessConflict($callback);
        $this->addToAssertionCount(1);
    }

    private function captureBusinessConflict(callable $callback): PaymentConfirmationException
    {
        try {
            $callback();
        } catch (PaymentConfirmationException $exception) {
            return $exception;
        }

        $this->fail('Expected a PaymentConfirmationException.');
    }

    private function assertFinancialSideEffectsAbsent(): void
    {
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }
}
