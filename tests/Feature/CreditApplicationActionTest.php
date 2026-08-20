<?php

namespace Tests\Feature;

use App\Actions\Credits\AppliedCreditResult;
use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\ConfirmPayment;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class CreditApplicationActionTest extends AuthorizationTestCase
{
    public function test_partial_credit_creates_one_confirmed_payment_and_partial_invoice(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '30.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 3000, $invoice, $balance);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $result->paymentId,
            'amount' => '30.00',
        ]);
        $this->assertNoTopUp();
    }

    public function test_exact_credit_pays_invoice_without_top_up(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 10000, $invoice, $balance);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNoTopUp();
    }

    public function test_excess_credit_is_split_automatically_and_remainder_stays_reusable(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '130.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 10000, $invoice, $balance);
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNoTopUp();
    }

    public function test_explicit_requested_amount_caps_application(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');

        $result = $this->action()->execute($invoice, 2500);

        $this->assertAppliedResult($result, 2500, $invoice, $balance);
        $this->assertSame('75.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    public function test_manual_application_uses_exact_amount_and_financial_snapshots(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '50.00');

        $result = $this->action()->executeManual($invoice, 3000, 5000, 10000);

        $this->assertTrue($result->applied);
        $this->assertSame(3000, $result->appliedAmountMinor);
        $this->assertDatabaseHas('payments', [
            'id' => $result->paymentId,
            'comment' => 'Вручную применён Credit Balance (30.00 ₼)',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'id' => $result->entryId,
            'description' => "Вручную применён к инвойсу #{$invoice->invoice_number}",
        ]);
        $this->assertSame('20.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(
            'Вручную применён Credit Balance (30.00 ₼)',
            Payment::query()->findOrFail($result->paymentId)->comment,
        );
    }

    public function test_manual_application_rejects_amount_above_current_safe_maximum_without_clamping(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '50.00');

        try {
            $this->action()->executeManual($invoice, 5100, 5000, 10000);
            $this->fail('Manual Credit amount above balance must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Можно использовать не более 50,00 ₼.',
                $exception->errors()['credit_amount'][0],
            );
        }

        $this->assertSame('50.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_manual_application_rejects_stale_snapshots_atomically(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '50.00');
        $this->action()->executeManual($invoice, 3000, 5000, 10000);

        try {
            $this->action()->executeManual($invoice->fresh(), 3000, 5000, 10000);
            $this->fail('Stale Credit snapshots must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Финансовые данные изменились. Обновите страницу и попробуйте снова.',
                $exception->errors()['credit_amount'][0],
            );
        }

        $this->assertSame('20.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_manual_application_can_follow_an_automatic_partial_application(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '20.00');
        $automatic = $this->action()->execute($invoice);
        $this->assertSame(2000, $automatic->appliedAmountMinor);

        $balance->fresh()->forceFill(['amount' => '30.00'])->saveQuietly();
        $manual = $this->action()->executeManual($invoice->fresh(), 3000, 3000, 8000);

        $this->assertTrue($manual->applied);
        $this->assertNotSame($automatic->paymentId, $manual->paymentId);
        $this->assertSame(2, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(2, CreditBalanceEntry::query()->where('invoice_id', $invoice->id)->where('type', 'applied')->count());
        $this->assertSame(50.0, $invoice->fresh()->paid_amount);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
    }

    public function test_pending_reservation_limits_credit_to_unreserved_capacity(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 3000, $invoice, $balance);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('70.00', $pending->fresh()->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $pending->id]);
        $this->assertNoTopUp();
    }

    public function test_fully_pending_reserved_invoice_is_a_clean_no_op(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $pending = $this->rawPayment($invoice, 'pending', '100.00');

        $result = $this->action()->execute($invoice);

        $this->assertNoOp($result, AppliedCreditResult::FULLY_RESERVED);
        $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_legacy_pending_over_reservation_is_a_clean_no_op(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $this->rawPayment($invoice, 'pending', '130.00');

        $result = $this->action()->execute($invoice);

        $this->assertNoOp($result, AppliedCreditResult::FULLY_RESERVED);
        $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_existing_confirmed_payment_reduces_settled_remaining(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $existing = $this->rawPayment($invoice, 'confirmed', '60.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 4000, $invoice, $balance);
        $this->assertSame('60.00', $existing->fresh()->getRawOriginal('amount'));
        $this->assertSame('60.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNoTopUp();
    }

    public function test_confirmed_and_pending_payments_both_reduce_available_capacity(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $confirmed = $this->rawPayment($invoice, 'confirmed', '20.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');

        $result = $this->action()->execute($invoice);

        $this->assertAppliedResult($result, 1000, $invoice, $balance);
        $this->assertSame('90.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertNoTopUp();
    }

    public function test_pending_can_confirm_after_credit_application_without_changing_credit_remainder(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');
        $this->action()->execute($invoice);

        app(ConfirmPayment::class)->execute($pending);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('confirmed', $pending->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertNoTopUp();
    }

    public function test_pending_can_cancel_after_credit_application_without_returning_applied_credit(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');
        $this->action()->execute($invoice);
        $this->actingAsPermissions([PermissionName::PaymentsCancel->value]);

        $this->patch(route('payments.cancel', $pending), [
            'cancel_payment_id' => $pending->id,
            'cancel_reason' => 'Reservation removed after Credit application',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_unsupported_invoice_states_are_rejected_without_mutation(): void
    {
        foreach (['draft', 'paid', 'cancelled'] as $status) {
            [$invoice, $balance] = $this->creditFixture('100.00', '100.00', $status);

            try {
                $this->action()->execute($invoice);
                $this->fail("Invoice state {$status} must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('credit', $exception->errors());
            }

            $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
            $this->assertDatabaseMissing('credit_balance_entries', ['invoice_id' => $invoice->id]);
            $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        }
    }

    public function test_missing_and_zero_credit_balances_are_distinct_clean_no_ops(): void
    {
        $invoiceWithoutBalance = $this->invoiceFixture('100.00');
        $missing = $this->action()->execute($invoiceWithoutBalance);
        $this->assertNoOp($missing, AppliedCreditResult::NO_CREDIT_BALANCE);
        $this->assertNull($missing->creditBalanceId);

        [$invoiceWithZero, $zeroBalance] = $this->creditFixture('100.00', '0.00');
        $zero = $this->action()->execute($invoiceWithZero);
        $this->assertNoOp($zero, AppliedCreditResult::ZERO_CREDIT);
        $this->assertSame($zeroBalance->id, $zero->creditBalanceId);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_invalid_persisted_invoice_or_credit_amount_is_rejected(): void
    {
        foreach (['0.00', '-0.01'] as $invoiceTotal) {
            [$invoice, $balance] = $this->creditFixture($invoiceTotal, '100.00');

            try {
                $this->action()->execute($invoice);
                $this->fail('Non-positive Invoice total must be rejected.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        }

        [$invoice, $negativeBalance] = $this->creditFixture('100.00', '-0.01');
        try {
            $this->action()->execute($invoice);
            $this->fail('Negative Credit Balance must be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('-0.01', $negativeBalance->fresh()->getRawOriginal('amount'));
    }

    public function test_minor_unit_boundaries_and_capacity_caps_are_exact(): void
    {
        foreach (['0.01', '1.20', '99999999.99'] as $amount) {
            [$invoice, $balance] = $this->creditFixture($amount, $amount);
            $minor = app(InvoicePaymentAvailabilityService::class)->toMinorUnits($amount);

            $result = $this->action()->execute($invoice);

            $this->assertAppliedResult($result, $minor, $invoice, $balance);
            $this->assertSame($amount, Payment::query()->findOrFail($result->paymentId)->getRawOriginal('amount'));
        }

        foreach ([[9999, '99.99'], [10000, '100.00'], [10001, '100.00']] as [$requested, $expected]) {
            [$invoice] = $this->creditFixture('100.00', '130.00');
            $result = $this->action()->execute($invoice, $requested);
            $this->assertSame($expected, Payment::query()->findOrFail($result->paymentId)->getRawOriginal('amount'));
        }
    }

    public function test_invalid_explicit_requested_amount_is_rejected_before_queries(): void
    {
        $invoice = $this->invoiceFixture('100.00');

        foreach ([0, -1, 10_000_000_000] as $requested) {
            $capture = (new DomainQueryRecorder)->capture(function () use ($invoice, $requested): void {
                try {
                    $this->action()->execute($invoice, $requested);
                    $this->fail('Invalid requested amount must be rejected.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            });

            $this->assertSame([], $capture['records']);
        }
    }

    public function test_action_uses_only_the_locked_invoice_company_credit_balance(): void
    {
        [$invoice, $correctBalance] = $this->creditFixture('100.00', '40.00');
        $otherCompany = $this->company('Other Credit Company');
        $otherBalance = $otherCompany->creditBalance()->create(['amount' => '90.00']);

        $result = $this->action()->execute($invoice);
        $payment = Payment::query()->findOrFail($result->paymentId);
        $entry = CreditBalanceEntry::query()->findOrFail($result->entryId);

        $this->assertSame($invoice->company_id, $payment->company_id);
        $this->assertSame($invoice->company_id, $correctBalance->company_id);
        $this->assertSame($correctBalance->id, $entry->credit_balance_id);
        $this->assertSame($invoice->id, $entry->invoice_id);
        $this->assertSame($payment->id, $entry->payment_id);
        $this->assertSame('90.00', $otherBalance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('credit_balance_entries', ['credit_balance_id' => $otherBalance->id]);
    }

    public function test_input_invoice_is_only_a_locator_and_fresh_locked_values_are_used(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '40.00');
        $persistedCompanyId = $invoice->company_id;
        $invoice->setAttribute('status', 'draft');
        $invoice->setAttribute('company_id', $this->company('Stale locator company')->id);
        $invoice->setAttribute('total_amount', '999.00');

        $result = $this->action()->execute($invoice);
        $payment = Payment::query()->findOrFail($result->paymentId);

        $this->assertSame(4000, $result->appliedAmountMinor);
        $this->assertSame($persistedCompanyId, $payment->company_id);
        $this->assertSame('40.00', $payment->getRawOriginal('amount'));
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
    }

    public function test_action_has_an_independent_minor_unit_path_and_never_calls_legacy_apply(): void
    {
        $source = file_get_contents(app_path('Actions/Credits/ApplyCreditToInvoice.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->apply(', $source);
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('floatval(', $source);
        $this->assertStringNotContainsString('round(', $source);
        $this->assertStringContainsString('toMinorUnits(', $source);
        $this->assertStringContainsString('fromMinorUnits(', $source);
    }

    public function test_multiple_applications_to_one_invoice_create_distinct_events(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $first = $this->action()->execute($invoice, 2500);
        $firstAllocation = DB::table('payment_allocations')
            ->where('payment_id', $first->paymentId)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $second = $this->action()->execute($invoice);

        $this->assertTrue($second->applied);
        $this->assertSame(7500, $second->appliedAmountMinor);
        $this->assertNotSame($first->paymentId, $second->paymentId);
        $this->assertNotSame($first->entryId, $second->entryId);
        $this->assertSame($balance->id, $second->creditBalanceId);
        $this->assertDatabaseCount('credit_balance_entries', 2);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame(
            $firstAllocation,
            DB::table('payment_allocations')
                ->where('payment_id', $first->paymentId)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    public function test_legacy_orphan_entry_does_not_block_a_new_exact_application(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $orphan = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '10.00',
            'invoice_id' => $invoice->id,
            'description' => 'Legacy orphan',
        ]);

        $result = $this->action()->execute($invoice);

        $this->assertTrue($result->applied);
        $this->assertSame(10000, $result->appliedAmountMinor);
        $this->assertNull($orphan->fresh()->payment_id);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('credit_balance_entries', 2);
    }

    public function test_failure_after_ledger_and_balance_mutation_before_payment_rolls_back(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $failPaymentInsert = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$failPaymentInsert): void {
            if ($failPaymentInsert
                && str_starts_with(strtolower(ltrim($query)), 'insert')
                && in_array('payments', DomainQueryRecorder::tablesInSql($query), true)) {
                throw new RuntimeException('credit-payment-insert-failure');
            }
        });

        try {
            $this->action()->execute($invoice);
            $this->fail('Payment insert failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('credit-payment-insert-failure', $exception->getMessage());
        } finally {
            $failPaymentInsert = false;
        }

        $this->assertRolledBack($invoice, $balance);
    }

    public function test_writer_failure_after_payment_link_rolls_back_and_retry_applies_once(): void
    {
        [$invoice, $balance] = $this->creditFixture('100.00', '100.00');
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('credit-writer-failure'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);

        try {
            $this->action()->execute($invoice);
            $this->fail('Writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('credit-writer-failure', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        }

        $this->assertRolledBack($invoice, $balance);

        $result = $this->action()->execute($invoice);
        $this->assertAppliedResult($result, 10000, $invoice, $balance);
        $this->assertDatabaseCount('credit_balance_entries', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_read_queries_are_bounded_for_payments_and_lines(): void
    {
        $pendingProfiles = [
            $this->queryProfile(pendingCount: 1),
            $this->queryProfile(pendingCount: 6),
        ];
        $confirmedProfiles = [
            $this->queryProfile(confirmedCount: 1),
            $this->queryProfile(confirmedCount: 6),
        ];
        $lineProfiles = [
            $this->queryProfile(lineCount: 1),
            $this->queryProfile(lineCount: 6),
        ];

        foreach ([$pendingProfiles, $confirmedProfiles, $lineProfiles] as $profiles) {
            $this->assertSame($profiles[0]['reads'], $profiles[1]['reads']);
            $this->assertGreaterThanOrEqual($profiles[0]['writes'], $profiles[1]['writes']);
            $this->assertNotContains('companies', $profiles[0]['tables']);
            $this->assertNotContains('companies', $profiles[1]['tables']);
        }

        $this->assertSame([8, 8], array_column($pendingProfiles, 'reads'));
        $this->assertSame([6, 6], array_column($pendingProfiles, 'writes'));
        $this->assertSame([8, 8], array_column($confirmedProfiles, 'reads'));
        $this->assertSame([7, 12], array_column($confirmedProfiles, 'writes'));
        $this->assertSame([8, 8], array_column($lineProfiles, 'reads'));
        $this->assertSame([6, 11], array_column($lineProfiles, 'writes'));
    }

    private function action(): ApplyCreditToInvoice
    {
        return app(ApplyCreditToInvoice::class);
    }

    /** @return array{Invoice, CreditBalance} */
    private function creditFixture(
        string $invoiceTotal,
        string $creditAmount,
        string $status = 'issued',
        int $lineCount = 1,
    ): array {
        $invoice = $this->invoiceFixture($invoiceTotal, $status, $lineCount);
        $balance = CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => $creditAmount,
        ]);

        return [$invoice, $balance];
    }

    private function invoiceFixture(
        string $total,
        string $status = 'issued',
        int $lineCount = 1,
    ): Invoice {
        $company = $this->company('Credit Action Company '.uniqid());
        $contract = $this->contract($company);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'CREDIT-ACTION-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
        $totalMinor = app(InvoicePaymentAvailabilityService::class)->toMinorUnits($total);
        $lineMinor = intdiv(max($totalMinor, 0), $lineCount);
        $remainder = max($totalMinor, 0) - ($lineMinor * $lineCount);

        for ($index = 0; $index < $lineCount; $index++) {
            $amountMinor = $lineMinor + ($index === $lineCount - 1 ? $remainder : 0);
            $invoice->lines()->create([
                'description' => 'Credit action line '.($index + 1),
                'amount' => app(InvoicePaymentAvailabilityService::class)
                    ->fromMinorUnits($amountMinor),
            ]);
        }

        return $invoice;
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
            'comment' => 'Credit application fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Payment::query()->findOrFail($id);
    }

    private function assertAppliedResult(
        AppliedCreditResult $result,
        int $expectedMinor,
        Invoice $invoice,
        CreditBalance $balance,
    ): void {
        $expectedAmount = app(InvoicePaymentAvailabilityService::class)->fromMinorUnits($expectedMinor);
        $this->assertTrue($result->applied);
        $this->assertSame($expectedMinor, $result->appliedAmountMinor);
        $this->assertNotNull($result->paymentId);
        $this->assertNotNull($result->entryId);
        $this->assertSame($balance->id, $result->creditBalanceId);
        $this->assertNull($result->noOpReason);
        $this->assertDatabaseHas('payments', [
            'id' => $result->paymentId,
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => now()->toDateString(),
            'amount' => $expectedAmount,
            'payment_method' => 'transfer',
            'status' => 'confirmed',
            'comment' => "Автоматически применён Credit Balance ({$expectedAmount} ₼)",
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'id' => $result->entryId,
            'credit_balance_id' => $balance->id,
            'invoice_id' => $invoice->id,
            'payment_id' => $result->paymentId,
            'type' => 'applied',
            'amount' => $expectedAmount,
            'description' => "Применён к инвойсу #{$invoice->invoice_number}",
        ]);
    }

    private function assertNoOp(AppliedCreditResult $result, string $reason): void
    {
        $this->assertFalse($result->applied);
        $this->assertSame(0, $result->appliedAmountMinor);
        $this->assertNull($result->paymentId);
        $this->assertNull($result->entryId);
        $this->assertSame($reason, $result->noOpReason);
    }

    private function assertNoTopUp(): void
    {
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up']);
    }

    private function assertRolledBack(Invoice $invoice, CreditBalance $balance): void
    {
        $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    /** @return array{reads: int, writes: int, tables: list<string>} */
    private function queryProfile(
        int $pendingCount = 0,
        int $confirmedCount = 0,
        int $lineCount = 1,
    ): array {
        [$invoice] = $this->creditFixture('100.00', '100.00', lineCount: $lineCount);

        for ($index = 0; $index < $pendingCount; $index++) {
            $this->rawPayment($invoice, 'pending', '1.00');
        }
        for ($index = 0; $index < $confirmedCount; $index++) {
            $this->rawPayment($invoice, 'confirmed', '1.00');
        }

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->action()->execute($invoice),
        );
        $reads = count(array_filter(
            $capture['records'],
            static fn (array $record): bool => preg_match('/^\s*(select|with)\b/i', $record['sql']) === 1,
        ));

        return [
            'reads' => $reads,
            'writes' => count($capture['records']) - $reads,
            'tables' => DomainQueryRecorder::tables($capture['records']),
        ];
    }
}
