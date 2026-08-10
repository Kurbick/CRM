<?php

namespace Tests\Feature;

use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\DomainQueryRecorder;

class InvoiceCreditApplicationIntegrationTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_issue_applies_partial_credit_and_preserves_web_response(): void
    {
        [$invoice, $line, $balance] = $this->fixture('30.00');

        $this->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', 'Инвойс успешно выставлен.');

        $payment = $invoice->payments()->where('status', 'confirmed')->sole();
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertCreditApplication($invoice, $payment, '30.00');
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_line_id' => $line->id,
            'amount' => '30.00',
        ]);
    }

    public function test_issue_applies_exact_credit_and_preserves_paid_flash(): void
    {
        [$invoice, , $balance] = $this->fixture('100.00');

        $this->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', 'Инвойс выставлен и полностью оплачен кредитным балансом.');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertCreditApplication($invoice, $invoice->payments()->sole(), '100.00');
    }

    public function test_issue_splits_excess_credit_without_creating_top_up(): void
    {
        [$invoice, , $balance] = $this->fixture('130.00');

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertCreditApplication($invoice, $invoice->payments()->sole(), '100.00');
    }

    public function test_pending_reservation_caps_real_web_credit_application(): void
    {
        [$invoice, $line, $balance] = $this->fixture('100.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $confirmed = $invoice->payments()->where('status', 'confirmed')->sole();
        $this->assertSame('30.00', $confirmed->getRawOriginal('amount'));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('70.00', $pending->getRawOriginal('amount'));
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertCreditApplication($invoice, $confirmed, '30.00');
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $confirmed->id,
            'invoice_line_id' => $line->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $pending->id]);

        $this->patch(route('payments.confirm', $pending))->assertSessionDoesntHaveErrors();

        $this->assertSame('confirmed', $pending->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('100.00', DB::table('payment_allocations')
            ->whereIn('payment_id', [$confirmed->id, $pending->id])
            ->sum('amount'));
        $this->assertSame(2, DB::table('payment_allocations')
            ->whereIn('payment_id', [$confirmed->id, $pending->id])
            ->count());
    }

    public function test_fully_reserved_and_legacy_over_reserved_invoices_do_not_apply_credit(): void
    {
        foreach (['100.00', '130.00'] as $pendingAmount) {
            [$invoice, , $balance] = $this->fixture('100.00');
            $pending = $this->rawPayment($invoice, 'pending', $pendingAmount);

            $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

            $this->assertSame('issued', $invoice->fresh()->status);
            $this->assertSame('pending', $pending->fresh()->status);
            $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
            $this->assertDatabaseMissing('credit_balance_entries', ['invoice_id' => $invoice->id]);
            $this->assertDatabaseMissing('payments', [
                'invoice_id' => $invoice->id,
                'status' => 'confirmed',
            ]);
        }
    }

    public function test_issue_without_company_credit_or_with_zero_credit_is_a_clean_no_op(): void
    {
        [$missingInvoice] = $this->fixture(null);
        [$zeroInvoice, , $zeroBalance] = $this->fixture('0.00');

        $this->post(route('invoices.issue', $missingInvoice))->assertSessionDoesntHaveErrors();
        $this->post(route('invoices.issue', $zeroInvoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('issued', $missingInvoice->fresh()->status);
        $this->assertSame('issued', $zeroInvoice->fresh()->status);
        $this->assertSame('0.00', $zeroBalance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $missingInvoice->id]);
        $this->assertDatabaseMissing('payments', ['invoice_id' => $zeroInvoice->id]);
    }

    public function test_issue_never_uses_another_company_credit(): void
    {
        [$invoice] = $this->fixture(null);
        [, , $otherBalance] = $this->fixture('100.00');

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $otherBalance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('credit_balance_entries', ['invoice_id' => $invoice->id]);
    }

    public function test_existing_orphan_entry_is_not_relinked_or_reapplied(): void
    {
        [$invoice, , $balance] = $this->fixture('100.00');
        $orphan = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '10.00',
            'invoice_id' => $invoice->id,
            'description' => 'Legacy orphan',
        ]);

        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertNull($orphan->fresh()->payment_id);
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertSame(1, CreditBalanceEntry::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_existing_confirmed_payment_still_blocks_issue_before_credit_mutation(): void
    {
        [$invoice, , $balance] = $this->fixture('100.00');
        $payment = $this->rawPayment($invoice, 'confirmed', '10.00');

        $this->post(route('invoices.issue', $invoice))->assertSessionHasErrors('issue');

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('100.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_outer_issue_transaction_rolls_back_credit_and_invoice_mutations_and_retry_succeeds(): void
    {
        [$invoice, , $balance] = $this->fixture('30.00', '2026-08-31');
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('web-issue-writer-failure'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
        $this->withoutExceptionHandling();

        try {
            $this->post(route('invoices.issue', $invoice));
            $this->fail('Writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('web-issue-writer-failure', $exception->getMessage());
        }

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('2026-08-31', $invoice->fresh()->getRawOriginal('due_date'));
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('credit_balance_entries', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseCount('payment_allocations', 0);

        $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        $this->withExceptionHandling();
        $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors();

        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(1, CreditBalanceEntry::query()->where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_outer_failure_rolls_back_subscription_schedule_and_occurrence_key(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Credit subscription '.uniqid()]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CREDIT-SUB-'.uniqid(),
            'start_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $subscription = Subscription::query()->forceCreate([
            'contract_id' => $contractId,
            'title' => 'Credit rollback subscription',
            'start_date' => '2026-01-31',
            'next_billing_date' => '2026-01-31',
            'billing_period' => 'monthly',
            'amount' => '100.00',
            'payment_terms' => 14,
            'status' => 'active',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'CREDIT-SUB-INV-'.uniqid(),
            'issue_date' => '2026-01-31',
            'due_date' => '2026-02-14',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => $subscription->title,
            'amount' => '100.00',
            'period_start' => '2026-01-31',
            'period_end' => '2026-02-27',
        ]);
        $balance = CreditBalance::query()->create([
            'company_id' => $companyId,
            'amount' => '30.00',
        ]);
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('subscription-rollback'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
        $this->withoutExceptionHandling();

        try {
            $this->post(route('invoices.issue', $invoice));
            $this->fail('Writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('subscription-rollback', $exception->getMessage());
        }

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertNull($line->fresh()->billing_occurrence_key);
        $this->assertSame('2026-01-31', $subscription->fresh()->getRawOriginal('next_billing_date'));
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('credit_balance_entries', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_web_issue_reads_are_bounded_for_pending_payments_and_lines(): void
    {
        $profiles = [];

        foreach ([[1, 1], [6, 1], [1, 6]] as [$pendingCount, $lineCount]) {
            [$invoice] = $this->fixture('100.00', lineCount: $lineCount);
            foreach (range(1, $pendingCount) as $index) {
                $this->rawPayment($invoice, 'pending', '1.00');
            }

            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->post(route('invoices.issue', $invoice))->assertSessionDoesntHaveErrors(),
            );
            $reads = array_values(array_filter(
                $capture['records'],
                static fn (array $record): bool => preg_match('/^\s*(select|with)\b/i', $record['sql']) === 1,
            ));
            $profiles[] = [
                'reads' => count($reads),
                'writes' => count($capture['records']) - count($reads),
                'readSql' => array_column($reads, 'sql'),
            ];
        }

        $this->assertSame([16, 16, 16], array_column($profiles, 'reads'));
        $this->assertSame([7, 7, 12], array_column($profiles, 'writes'));
        $this->assertSame($profiles[0]['reads'], $profiles[1]['reads']);
        $this->assertSame($profiles[0]['reads'], $profiles[2]['reads']);
        $this->assertGreaterThanOrEqual($profiles[0]['writes'], $profiles[2]['writes']);
        foreach ($profiles as $profile) {
            $this->assertCount(0, array_filter(
                $profile['readSql'],
                static fn (string $sql): bool => str_contains(strtolower($sql), ' from `companies`'),
            ));
        }
    }

    /** @return array{Invoice, InvoiceLine, CreditBalance|null} */
    private function fixture(
        ?string $credit,
        string $dueDate = '2026-08-31',
        int $lineCount = 1,
    ): array {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Credit Web '.uniqid()]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CREDIT-WEB-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'CREDIT-WEB-INV-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => $dueDate,
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $lineAmount = intdiv(10000, $lineCount);
        $remainder = 10000 - ($lineAmount * $lineCount);
        $firstLine = null;

        foreach (range(1, $lineCount) as $index) {
            $minor = $lineAmount + ($index === $lineCount ? $remainder : 0);
            $line = $invoice->lines()->create([
                'description' => "Credit service {$index}",
                'amount' => sprintf('%d.%02d', intdiv($minor, 100), $minor % 100),
            ]);
            $firstLine ??= $line;
        }

        $balance = $credit === null ? null : CreditBalance::query()->create([
            'company_id' => $companyId,
            'amount' => $credit,
        ]);

        return [$invoice, $firstLine, $balance];
    }

    private function rawPayment(Invoice $invoice, string $status, string $amount): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-05',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]));
    }

    private function assertCreditApplication(Invoice $invoice, Payment $payment, string $amount): void
    {
        $this->assertSame($invoice->company_id, $payment->company_id);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame($amount, $payment->getRawOriginal('amount'));
        $this->assertSame('transfer', $payment->payment_method);
        $this->assertDatabaseHas('credit_balance_entries', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'type' => 'applied',
            'amount' => $amount,
        ]);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'invoice_id' => $invoice->id,
            'type' => 'top_up',
        ]);
    }
}
