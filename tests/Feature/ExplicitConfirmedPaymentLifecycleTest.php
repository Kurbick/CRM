<?php

namespace Tests\Feature;

use App\Actions\Payments\ApplyConfirmedPaymentLifecycle;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ExplicitConfirmedPaymentLifecycleTest extends AuthorizationTestCase
{
    public function test_direct_confirmed_model_save_has_no_hidden_financial_side_effects(): void
    {
        $invoice = $this->invoiceFixture('issued', ['100.00']);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => Payment::query()->create($this->paymentAttributes($invoice, 'confirmed', '130.00'))
        );

        $this->assertSame('confirmed', $capture['result']->status);
        $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_minor_unit_boundaries_drive_exact_invoice_status_and_overpayment(): void
    {
        $cases = [
            ['0.01', ['0.01'], 'paid', null],
            ['99.99', ['100.00'], 'partially_paid', null],
            ['100.00', ['100.00'], 'paid', null],
            ['100.01', ['100.00'], 'paid', '0.01'],
            ['1.20', ['2.00'], 'partially_paid', null],
            ['99999999.99', ['99999999.99'], 'paid', null],
        ];

        foreach ($cases as [$amount, $lines, $expectedStatus, $credit]) {
            $invoice = $this->invoiceFixture('issued', $lines);

            $payment = app(CreateConfirmedPayment::class)->execute(
                $invoice,
                $this->confirmedAttributes($amount)
            );

            $this->assertSame('confirmed', $payment->status);
            $this->assertSame($expectedStatus, $invoice->fresh()->status);
            $this->assertSame($amount, $payment->fresh()->getRawOriginal('amount'));

            if ($credit === null) {
                $this->assertDatabaseMissing('credit_balance_entries', ['payment_id' => $payment->id]);
            } else {
                $this->assertDatabaseHas('credit_balance_entries', [
                    'type' => 'top_up',
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $credit,
                ]);
            }
        }
    }

    public function test_negative_legacy_and_cancelled_payments_do_not_reduce_or_increase_confirmed_total(): void
    {
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $this->rawPayment($invoice, 'confirmed', '-50.00');
        $this->rawPayment($invoice, 'cancelled', '500.00');

        $payment = app(CreateConfirmedPayment::class)->execute(
            $invoice,
            $this->confirmedAttributes('100.00')
        );

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('credit_balance_entries', ['payment_id' => $payment->id]);
        $this->assertSame('100.00', PaymentAllocation::query()->sum('amount'));
    }

    public function test_repeated_explicit_lifecycle_is_idempotent_for_credit_and_allocations(): void
    {
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = app(CreateConfirmedPayment::class)->execute(
            $invoice,
            $this->confirmedAttributes('130.00')
        );
        $allocation = PaymentAllocation::query()->sole()->getRawOriginal();
        $balance = CreditBalance::query()->sole()->getRawOriginal();

        DB::transaction(function () use ($invoice, $payment): void {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            app(ApplyConfirmedPaymentLifecycle::class)->execute($lockedInvoice, $lockedPayment);
        });

        $this->assertSame($allocation, PaymentAllocation::query()->sole()->getRawOriginal());
        $this->assertSame($balance, CreditBalance::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('credit_balance_entries', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_lifecycle_rejects_relationship_company_state_and_amount_invariants(): void
    {
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $otherInvoice = $this->invoiceFixture('issued', ['100.00']);
        $otherCompanyId = $otherInvoice->company_id;
        $cases = [
            $this->rawPayment($otherInvoice, 'confirmed', '10.00'),
            $this->rawPayment($invoice, 'confirmed', '10.00', $otherCompanyId),
            $this->rawPayment($invoice, 'pending', '10.00'),
            $this->rawPayment($invoice, 'confirmed', '0.00'),
        ];

        foreach ($cases as $payment) {
            try {
                DB::transaction(function () use ($invoice, $payment): void {
                    $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    app(ApplyConfirmedPaymentLifecycle::class)->execute($lockedInvoice, $lockedPayment);
                });
                $this->fail('Invalid lifecycle context must fail.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_immediate_confirmed_web_store_preserves_server_ownership_and_response_contract(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $otherInvoice = $this->invoiceFixture('issued', ['100.00']);

        $this->post(route('payments.store', $invoice), [
            ...$this->confirmedAttributes('40.00'),
            'status' => 'confirmed',
            'company_id' => $otherInvoice->company_id,
            'invoice_id' => $otherInvoice->id,
        ])->assertRedirect(route('home'))
            ->assertSessionHas('success', 'Платёж успешно зарегистрирован.')
            ->assertSessionDoesntHaveErrors();

        $payment = $invoice->payments()->sole();
        $this->assertSame($invoice->company_id, $payment->company_id);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'amount' => '40.00',
        ]);
    }

    public function test_immediate_confirmed_web_store_preserves_exact_and_overpayment_behavior(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        foreach ([['100.00', null], ['130.00', '30.00']] as [$amount, $credit]) {
            $invoice = $this->invoiceFixture('issued', ['100.00']);
            $this->post(route('payments.store', $invoice), [
                ...$this->confirmedAttributes($amount),
                'status' => 'confirmed',
            ])->assertSessionDoesntHaveErrors();
            $payment = $invoice->payments()->sole();

            $this->assertSame('paid', $invoice->fresh()->status);
            $this->assertDatabaseHas('payment_allocations', [
                'payment_id' => $payment->id,
                'amount' => '100.00',
            ]);

            if ($credit === null) {
                $this->assertDatabaseMissing('credit_balance_entries', ['payment_id' => $payment->id]);
            } else {
                $this->assertDatabaseHas('credit_balance_entries', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $credit,
                ]);
            }
        }
    }

    public function test_pending_web_store_remains_free_of_confirmed_lifecycle_effects(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);

        $this->post(route('payments.store', $invoice), [
            ...$this->confirmedAttributes('40.00'),
            'status' => 'pending',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('pending', $invoice->payments()->sole()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_immediate_confirmed_insert_rolls_back_when_writer_fails(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $this->bindFailingWriter('immediate-writer-failure');
        $this->withoutExceptionHandling();

        try {
            $this->post(route('payments.store', $invoice), [
                ...$this->confirmedAttributes('130.00'),
                'status' => 'confirmed',
            ]);
            $this->fail('Writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('immediate-writer-failure', $exception->getMessage());
        }

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_credit_entry_failure_rolls_back_confirmation_and_retry_succeeds(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '130.00');
        CreditBalanceEntry::created(function (): void {
            throw new RuntimeException('credit-entry-failure');
        });
        $this->withoutExceptionHandling();

        try {
            $this->patch(route('payments.confirm', $payment));
            $this->fail('Credit entry failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('credit-entry-failure', $exception->getMessage());
        } finally {
            CreditBalanceEntry::flushEventListeners();
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);

        $this->patch(route('payments.confirm', $payment))->assertSessionDoesntHaveErrors();
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_invoice_issue_applies_same_company_credit_links_payment_and_runs_lifecycle(): void
    {
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);
        $invoice = $this->invoiceFixture('draft', ['100.00']);
        CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);

        $this->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('home'))
            ->assertSessionDoesntHaveErrors();

        $payment = $invoice->payments()->sole();
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame('30.00', $payment->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up']);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'amount' => '30.00',
        ]);
    }

    public function test_invoice_issue_never_applies_another_company_credit_balance(): void
    {
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);
        $invoice = $this->invoiceFixture('draft', ['100.00']);
        $otherInvoice = $this->invoiceFixture('draft', ['100.00']);
        $otherBalance = CreditBalance::query()->create([
            'company_id' => $otherInvoice->company_id,
            'amount' => '100.00',
        ]);

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $otherBalance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_invoice_issue_writer_failure_rolls_back_credit_entry_link_payment_and_invoice(): void
    {
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);
        $invoice = $this->invoiceFixture('draft', ['100.00']);
        $balance = CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $this->bindFailingWriter('issue-writer-failure');
        $this->withoutExceptionHandling();

        try {
            $this->post(route('invoices.issue', $invoice));
            $this->fail('Issue writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('issue-writer-failure', $exception->getMessage());
        }

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_immediate_creation_queries_are_bounded_independently_of_existing_payment_count(): void
    {
        $counts = [];

        foreach ([1, 6] as $paymentCount) {
            $invoice = $this->invoiceFixture('issued', ['100.00']);
            foreach (range(1, $paymentCount) as $index) {
                $this->rawPayment($invoice, 'cancelled', '1.00');
            }

            $capture = (new DomainQueryRecorder)->capture(
                fn () => app(CreateConfirmedPayment::class)->execute(
                    $invoice,
                    $this->confirmedAttributes('5.00')
                )
            );
            $tables = DomainQueryRecorder::tables($capture['records']);

            $this->assertContains('invoices', $tables);
            $this->assertContains('payments', $tables);
            $this->assertContains('invoice_lines', $tables);
            $this->assertContains('payment_allocations', $tables);
            $this->assertNotContains('companies', $tables);
            $this->assertNotContains('credit_balances', $tables);
            $counts[] = DomainQueryRecorder::count($capture['records']);
            $this->assertLessThanOrEqual(15, end($counts));
        }

        $this->assertSame($counts[0], $counts[1]);
    }

    public function test_invoice_issue_credit_queries_are_bounded_for_one_and_six_lines(): void
    {
        $this->actingAsPermissions([
            PermissionName::InvoicesIssue->value,
            PermissionName::InvoicesView->value,
        ]);
        $counts = [];

        foreach ([1, 6] as $lineCount) {
            $invoice = $this->invoiceFixture('draft', array_fill(0, $lineCount, '10.00'));
            CreditBalance::query()->create([
                'company_id' => $invoice->company_id,
                'amount' => '5.00',
            ]);

            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->post(route('invoices.issue', $invoice))
            );
            $capture['result']->assertSessionDoesntHaveErrors();
            $tables = DomainQueryRecorder::tables($capture['records']);

            $this->assertContains('invoices', $tables);
            $this->assertContains('invoice_lines', $tables);
            $this->assertContains('payments', $tables);
            $this->assertContains('credit_balances', $tables);
            $this->assertContains('credit_balance_entries', $tables);
            $this->assertContains('payment_allocations', $tables);
            $this->assertNotContains('companies', $tables);
            $counts[] = DomainQueryRecorder::count($capture['records']);
            $this->assertLessThanOrEqual(24, end($counts));
        }

        $this->assertSame($counts[0], $counts[1]);
    }

    /** @param list<string> $lineAmounts */
    private function invoiceFixture(string $status, array $lineAmounts): Invoice
    {
        $company = $this->company('Explicit lifecycle '.uniqid());
        $contract = $this->contract($company);
        $totalMinor = array_sum(array_map(
            fn (string $amount): int => app(InvoicePaymentAvailabilityService::class)
                ->toMinorUnits($amount),
            $lineAmounts
        ));
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'EXPLICIT-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => app(InvoicePaymentAvailabilityService::class)
                ->fromMinorUnits($totalMinor),
            'status' => $status,
        ]);

        foreach ($lineAmounts as $index => $amount) {
            $invoice->lines()->create([
                'description' => 'Explicit line '.($index + 1),
                'amount' => $amount,
            ]);
        }

        return $invoice;
    }

    /** @return array{payment_date: string, amount: string, payment_method: string, comment: string} */
    private function confirmedAttributes(string $amount): array
    {
        return [
            'payment_date' => '2026-08-05',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'comment' => 'Explicit confirmed lifecycle',
        ];
    }

    /** @return array<string, mixed> */
    private function paymentAttributes(
        Invoice $invoice,
        string $status,
        string $amount,
        ?int $companyId = null
    ): array {
        return [
            'invoice_id' => $invoice->id,
            'company_id' => $companyId ?? $invoice->company_id,
            'payment_date' => '2026-08-05',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function rawPayment(
        Invoice $invoice,
        string $status,
        string $amount,
        ?int $companyId = null
    ): Payment {
        $id = DB::table('payments')->insertGetId(
            $this->paymentAttributes($invoice, $status, $amount, $companyId)
        );

        return Payment::query()->findOrFail($id);
    }

    private function bindFailingWriter(string $message): void
    {
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException($message));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
    }
}
