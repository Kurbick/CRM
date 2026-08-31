<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Http\Controllers\Web\InvoiceController;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\TwoConnectionDatabaseHarness;

class InvoiceCreditApplicationConcurrencyTest extends FinancialTestCase
{
    /** @var list<int> */
    private array $companyIds = [];

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function test_two_physical_connections_serialize_same_invoice_issue(): void
    {
        [$invoice, , $balance, $subscription] = $this->draftFixture('100.00', subscription: true);
        $result = $this->harness()->runBlockedPair(
            $this->issueOperation($invoice->id),
            $this->issueOperation($invoice->id),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertNotSame($result['first_connection_id'], $result['second_connection_id']);
        $this->assertMatchesRegularExpression($this->invoiceLockPattern(), $result['paused_sql']);
        $this->assertMatchesRegularExpression($this->invoiceLockPattern(), $result['waiting_sql']);
        $this->assertTrue($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame(ValidationException::class, $result['second']['exception']);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('2026-02-28', $subscription->fresh()->getRawOriginal('next_billing_date'));
        $this->assertSame(1, $invoice->lines()->whereNotNull('billing_occurrence_key')->count());
        $this->assertExactApplicationState([$invoice], 10000);
    }

    public function test_two_invoices_cannot_overspend_one_locked_credit_balance(): void
    {
        [$firstInvoice, , $balance] = $this->issuedFixture('80.00', '100.00');
        [$secondInvoice] = $this->issuedFixture('80.00', null, $firstInvoice->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->applicationOperation($firstInvoice->id),
            $this->applicationOperation($secondInvoice->id),
            $this->creditBalanceLockPattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertNotSame($result['first_connection_id'], $result['second_connection_id']);
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(8000, $result['first']['value']['applied_minor']);
        $this->assertSame(2000, $result['second']['value']['applied_minor']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertExactApplicationState([$firstInvoice, $secondInvoice], 10000);
        $this->assertSame('paid', $firstInvoice->fresh()->status);
        $this->assertSame('partially_paid', $secondInvoice->fresh()->status);
    }

    public function test_pending_capacity_remains_bounded_during_same_invoice_race(): void
    {
        [$invoice, , $balance] = $this->issuedFixture('100.00', '100.00');
        $pending = $this->rawPayment($invoice, 'pending', '70.00');
        $result = $this->harness()->runBlockedPair(
            $this->applicationOperation($invoice->id),
            $this->applicationOperation($invoice->id),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertNotSame($result['first_connection_id'], $result['second_connection_id']);
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(3000, $result['first']['value']['applied_minor']);
        $this->assertSame(0, $result['second']['value']['applied_minor']);
        $this->assertSame('fully_reserved', $result['second']['value']['reason']);
        $this->assertSame('70.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $pending->id]);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertExactApplicationState([$invoice], 3000);
    }

    public function test_legacy_orphan_does_not_block_concurrent_application(): void
    {
        [$invoice, , $balance] = $this->issuedFixture('100.00', '50.00');
        $orphan = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '10.00',
            'invoice_id' => $invoice->id,
            'description' => 'Concurrent legacy orphan',
        ]);
        $result = $this->harness()->runBlockedPair(
            $this->applicationOperation($invoice->id),
            $this->applicationOperation($invoice->id),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(5000, $result['first']['value']['applied_minor']);
        $this->assertSame('zero_credit', $result['second']['value']['reason']);
        $this->assertNull($orphan->fresh()->payment_id);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(2, CreditBalanceEntry::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_failed_issue_rolls_back_releases_lock_and_concurrent_retry_succeeds_once(): void
    {
        [$invoice, $line, $balance, $subscription] = $this->draftFixture('30.00', subscription: true);
        $result = $this->harness()->runBlockedPair(
            $this->failingIssueOperation($invoice->id),
            $this->issueOperation($invoice->id),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertFalse($result['first']['ok']);
        $this->assertSame(RuntimeException::class, $result['first']['exception']);
        $this->assertSame('concurrent-writer-failure', $result['first']['message']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('2026-02-28', $subscription->fresh()->getRawOriginal('next_billing_date'));
        $this->assertNotNull($line->fresh()->billing_occurrence_key);
        $this->assertExactApplicationState([$invoice], 3000);
    }

    public function test_missing_balance_is_no_op_and_company_organization_index_owns_creation_race(): void
    {
        [$invoice] = $this->issuedFixture('100.00');
        $result = app(ApplyCreditToInvoice::class)->execute($invoice);
        $indexes = collect(DB::select('SHOW INDEXES FROM credit_balances'));

        $this->assertFalse($result->applied);
        $this->assertSame('no_credit_balance', $result->noOpReason);
        $this->assertDatabaseMissing('credit_balances', ['company_id' => $invoice->company_id]);
        $this->assertTrue($indexes->contains(fn (object $index): bool => $index->Key_name === 'credit_balances_company_organization_unique'
            && (int) $index->Non_unique === 0
            && $index->Column_name === 'company_id'));
        $this->assertTrue($indexes->contains(fn (object $index): bool => $index->Key_name === 'credit_balances_company_organization_unique'
            && (int) $index->Non_unique === 0
            && $index->Column_name === 'organization_id'));
    }

    /** @return callable(): array{issued: bool} */
    private function issueOperation(int $invoiceId): callable
    {
        $userId = (int) $this->authenticatedUser->id;

        return static function () use ($invoiceId, $userId): array {
            Auth::loginUsingId($userId);
            app(InvoiceController::class)->issue(
                Invoice::query()->findOrFail($invoiceId),
                app(ApplyCreditToInvoice::class),
            );

            return ['issued' => true];
        };
    }

    /** @return callable(): array{issued: bool} */
    private function failingIssueOperation(int $invoiceId): callable
    {
        $userId = (int) $this->authenticatedUser->id;

        return static function () use ($invoiceId, $userId): array {
            $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
            $writer->shouldReceive('synchronize')
                ->once()
                ->andThrow(new RuntimeException('concurrent-writer-failure'));
            app()->instance(InvoicePaymentAllocationWriter::class, $writer);
            Auth::loginUsingId($userId);
            app(InvoiceController::class)->issue(
                Invoice::query()->findOrFail($invoiceId),
                app(ApplyCreditToInvoice::class),
            );

            return ['issued' => true];
        };
    }

    /** @return callable(): array{applied_minor: int, reason: string|null} */
    private function applicationOperation(int $invoiceId): callable
    {
        return static function () use ($invoiceId): array {
            $result = app(ApplyCreditToInvoice::class)->execute(
                Invoice::query()->findOrFail($invoiceId),
            );

            return [
                'applied_minor' => $result->appliedAmountMinor,
                'reason' => $result->noOpReason,
            ];
        };
    }

    /** @return array{Invoice, InvoiceLine, CreditBalance, Subscription|null} */
    private function draftFixture(string $credit, bool $subscription = false): array
    {
        [$invoice, $line, $balance, $createdSubscription] = $this->fixture(
            total: '100.00',
            credit: $credit,
            status: 'draft',
            subscription: $subscription,
        );

        return [$invoice, $line, $balance, $createdSubscription];
    }

    /** @return array{Invoice, InvoiceLine, CreditBalance|null, null} */
    private function issuedFixture(
        string $total,
        ?string $credit = null,
        ?int $companyId = null,
    ): array {
        return $this->fixture($total, $credit, 'issued', false, $companyId);
    }

    /** @return array{Invoice, InvoiceLine, CreditBalance|null, Subscription|null} */
    private function fixture(
        string $total,
        ?string $credit,
        string $status,
        bool $subscription,
        ?int $companyId = null,
    ): array {
        if ($companyId === null) {
            $companyId = DB::table('companies')->insertGetId([
                'name' => 'Concurrency '.uniqid(),
            ]);
            $this->companyIds[] = $companyId;
        }
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONCURRENCY-'.uniqid(),
            'start_date' => '2024-01-01',
            'status' => 'active',
        ]);
        $createdSubscription = null;
        if ($subscription) {
            $createdSubscription = Subscription::query()->forceCreate([
                'contract_id' => $contractId,
                'title' => 'Concurrent subscription',
                'start_date' => '2026-01-31',
                'next_billing_date' => '2026-01-31',
                'billing_period' => 'monthly',
                'amount' => $total,
                'payment_terms' => 14,
                'status' => 'active',
            ]);
        }
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'CONCURRENCY-INV-'.uniqid(),
            'issue_date' => '2026-01-31',
            'due_date' => '2026-02-14',
            'total_amount' => $total,
            'status' => $status,
        ]);
        $line = $invoice->lines()->create([
            'subscription_id' => $createdSubscription?->id,
            'description' => 'Concurrent line',
            'amount' => $total,
            'period_start' => $createdSubscription === null ? null : '2026-01-31',
            'period_end' => $createdSubscription === null ? null : '2026-02-27',
        ]);
        $balance = $credit === null ? null : CreditBalance::query()->firstOrCreate(
            ['company_id' => $companyId],
            ['amount' => $credit],
        );

        return [$invoice, $line, $balance, $createdSubscription];
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

    /** @param list<Invoice> $invoices */
    private function assertExactApplicationState(array $invoices, int $expectedAppliedMinor): void
    {
        $invoiceIds = array_map(fn (Invoice $invoice): int => (int) $invoice->id, $invoices);
        $entries = CreditBalanceEntry::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('type', 'applied')
            ->get();
        $payments = Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'confirmed')
            ->get();

        $expectedApplied = (float) $expectedAppliedMinor / 100;
        $this->assertSame($expectedApplied, (float) $entries->sum('amount'));
        $this->assertSame($entries->count(), $payments->count());
        $this->assertSame($expectedApplied, (float) $payments->sum('amount'));
        $this->assertSame($expectedApplied, (float) DB::table('payment_allocations')
            ->whereIn('payment_id', $payments->modelKeys())
            ->sum('amount'));
        $this->assertSame(0, CreditBalanceEntry::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('type', 'top_up')
            ->count());

        foreach ($entries as $entry) {
            $payment = $payments->firstWhere('id', $entry->payment_id);
            $this->assertNotNull($payment);
            $this->assertSame((int) $entry->invoice_id, (int) $payment->invoice_id);
            $this->assertSame((int) $entry->creditBalance->company_id, (int) $payment->company_id);
            $this->assertSame($entry->getRawOriginal('amount'), $payment->getRawOriginal('amount'));
        }
    }

    private function cleanupFixtures(): void
    {
        if ($this->companyIds === []) {
            return;
        }

        $invoiceIds = DB::table('invoices')->whereIn('company_id', $this->companyIds)->pluck('id');
        $paymentIds = DB::table('payments')->whereIn('invoice_id', $invoiceIds)->pluck('id');
        $lineIds = DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->pluck('id');
        DB::table('payment_allocations')
            ->whereIn('payment_id', $paymentIds)
            ->orWhereIn('invoice_line_id', $lineIds)
            ->delete();
        DB::table('credit_balance_entries')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('payments')->whereIn('id', $paymentIds)->delete();
        DB::table('invoice_lines')->whereIn('id', $lineIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        DB::table('credit_balances')->whereIn('company_id', $this->companyIds)->delete();
        DB::table('subscriptions')->whereIn(
            'contract_id',
            DB::table('contracts')->whereIn('company_id', $this->companyIds)->pluck('id'),
        )->delete();
        DB::table('contracts')->whereIn('company_id', $this->companyIds)->delete();
        DB::table('companies')->whereIn('id', $this->companyIds)->delete();
    }

    private function harness(): TwoConnectionDatabaseHarness
    {
        return new TwoConnectionDatabaseHarness;
    }

    private function invoiceLockPattern(): string
    {
        return '/from [`"]?invoices[`"]?.*for update/is';
    }

    private function creditBalanceLockPattern(): string
    {
        return '/from [`"]?credit_balances[`"]?.*for update/is';
    }
}
