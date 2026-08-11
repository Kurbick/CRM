<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\TwoConnectionDatabaseHarness;

class PaymentCancellationConcurrencyTest extends FinancialTestCase
{
    /** @var list<int> */
    private array $companyIds = [];

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function test_same_normal_payment_is_cancelled_once_across_two_physical_connections(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->confirmedPayment($invoice, '100.00');
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($payment->id, 'first cancellation'),
            $this->cancelOperation($payment->id, 'second cancellation'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame(ValidationException::class, $result['second']['exception']);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('first cancellation', $payment->fresh()->cancel_reason);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
        $this->assertSame(0, CreditBalanceEntry::query()->where('payment_id', $payment->id)->count());
    }

    public function test_same_credit_payment_restores_credit_once(): void
    {
        $invoice = $this->invoice('100.00');
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($payment->id, 'credit winner'),
            $this->cancelOperation($payment->id, 'credit loser'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame(ValidationException::class, $result['second']['exception']);
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertLedgerPair($payment, 'applied', 'applied_reversal', '30.00');
        $this->assertCancelledWithoutAllocations($payment);
    }

    public function test_same_top_up_payment_decrements_credit_once(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->confirmedPayment($invoice, '130.00');
        $balance = $this->companyBalance($invoice);
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($payment->id, 'top-up winner'),
            $this->cancelOperation($payment->id, 'top-up loser'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame(ValidationException::class, $result['second']['exception']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertLedgerPair($payment, 'top_up', 'top_up_reversal', '30.00');
        $this->assertCancelledWithoutAllocations($payment);
    }

    public function test_different_payments_on_one_invoice_serialize_and_recalculate_exactly(): void
    {
        $invoice = $this->invoice('100.00');
        $first = $this->confirmedPayment($invoice, '60.00');
        $second = $this->confirmedPayment($invoice, '40.00');
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($first->id, 'cancel 60'),
            $this->cancelOperation($second->id, 'cancel 40'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertCancelledWithoutAllocations($first);
        $this->assertCancelledWithoutAllocations($second);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->payments()->where('status', 'confirmed')->count());
    }

    public function test_credit_cancellation_restoration_serializes_before_other_invoice_application(): void
    {
        $invoiceA = $this->invoice('30.00');
        $balance = $this->balance($invoiceA, '30.00');
        $creditPayment = $this->applyCredit($invoiceA);
        $invoiceB = $this->invoice('30.00', $invoiceA->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($creditPayment->id, 'restore for B'),
            $this->applyOperation($invoiceB->id),
            $this->creditBalanceUpdatePattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->creditBalanceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(3000, $result['second']['value']['applied_minor']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertLedgerPair($creditPayment, 'applied', 'applied_reversal', '30.00');
        $newPayment = Payment::query()->findOrFail($result['second']['value']['payment_id']);
        $this->assertSame('confirmed', $newPayment->status);
        $this->assertSame('30.00', $newPayment->getRawOriginal('amount'));
    }

    public function test_top_up_cancellation_wins_and_prevents_downstream_spend(): void
    {
        $sourceInvoice = $this->invoice('100.00');
        $sourcePayment = $this->confirmedPayment($sourceInvoice, '130.00');
        $balance = $this->companyBalance($sourceInvoice);
        $downstream = $this->invoice('30.00', $sourceInvoice->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($sourcePayment->id, 'reverse before spend'),
            $this->applyOperation($downstream->id),
            $this->creditBalanceUpdatePattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->creditBalanceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(0, $result['second']['value']['applied_minor']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertLedgerPair($sourcePayment, 'top_up', 'top_up_reversal', '30.00');
        $this->assertDatabaseMissing('payments', ['invoice_id' => $downstream->id]);
    }

    public function test_downstream_spend_wins_and_blocks_top_up_cancellation(): void
    {
        $sourceInvoice = $this->invoice('100.00');
        $sourcePayment = $this->confirmedPayment($sourceInvoice, '130.00');
        $balance = $this->companyBalance($sourceInvoice);
        $downstream = $this->invoice('30.00', $sourceInvoice->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->applyOperation($downstream->id),
            $this->cancelOperation($sourcePayment->id, 'unsafe reversal'),
            $this->creditBalanceLockPattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->creditBalanceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame(ValidationException::class, $result['second']['exception']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('confirmed', $sourcePayment->fresh()->status);
        $this->assertSame(0, CreditBalanceEntry::query()->where('type', 'top_up_reversal')->where('payment_id', $sourcePayment->id)->count());
        $this->assertSame(1, CreditBalanceEntry::query()->where('type', 'applied')->where('invoice_id', $downstream->id)->count());
    }

    public function test_failed_credit_cancellation_is_invisible_then_waiter_uses_original_state_and_retry_is_exact(): void
    {
        $invoiceA = $this->invoice('30.00');
        $balance = $this->balance($invoiceA, '30.00');
        $creditPayment = $this->applyCredit($invoiceA);
        $invoiceB = $this->invoice('30.00', $invoiceA->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->failingCancelOperation($creditPayment->id),
            $this->applyOperation($invoiceB->id),
            $this->creditBalanceUpdatePattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->creditBalanceLockPattern());
        $this->assertFalse($result['first']['ok']);
        $this->assertSame(RuntimeException::class, $result['first']['exception']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(0, $result['second']['value']['applied_minor']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('confirmed', $creditPayment->fresh()->status);
        $this->assertSame(0, CreditBalanceEntry::query()->where('type', 'applied_reversal')->where('payment_id', $creditPayment->id)->count());

        app(CancelPayment::class)->execute($creditPayment->fresh(), 'clean retry');
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertLedgerPair($creditPayment, 'applied', 'applied_reversal', '30.00');
        $this->assertCancelledWithoutAllocations($creditPayment);
    }

    public function test_failed_top_up_cancellation_is_invisible_then_waiter_spends_original_credit(): void
    {
        $sourceInvoice = $this->invoice('100.00');
        $sourcePayment = $this->confirmedPayment($sourceInvoice, '130.00');
        $balance = $this->companyBalance($sourceInvoice);
        $downstream = $this->invoice('30.00', $sourceInvoice->company_id);
        $result = $this->harness()->runBlockedPair(
            $this->failingCancelOperation($sourcePayment->id),
            $this->applyOperation($downstream->id),
            $this->creditBalanceUpdatePattern(),
            $this->creditBalanceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->creditBalanceLockPattern());
        $this->assertFalse($result['first']['ok']);
        $this->assertSame(RuntimeException::class, $result['first']['exception']);
        $this->assertTrue($result['second']['ok']);
        $this->assertSame(3000, $result['second']['value']['applied_minor']);
        $this->assertSame('0.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('confirmed', $sourcePayment->fresh()->status);
        $this->assertSame(0, CreditBalanceEntry::query()->where('type', 'top_up_reversal')->where('payment_id', $sourcePayment->id)->count());
        $this->assertSame(1, CreditBalanceEntry::query()->where('type', 'applied')->where('invoice_id', $downstream->id)->count());
    }

    public function test_top_up_creation_and_same_payment_cancellation_serialize_as_complete_lifecycles(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->pendingPayment($invoice, '130.00');
        $result = $this->harness()->runBlockedPair(
            $this->confirmOperation($payment->id),
            $this->cancelOperation($payment->id, 'cancel after committed confirmation'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertTrue($result['first']['ok']);
        $this->assertTrue($result['second']['ok']);
        $this->assertCancelledWithoutAllocations($payment);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('0.00', $this->companyBalance($invoice)->getRawOriginal('amount'));
        $this->assertLedgerPair($payment, 'top_up', 'top_up_reversal', '30.00');
    }

    public function test_pre_existing_credit_reversal_makes_both_concurrent_cancellations_fail_closed(): void
    {
        $invoice = $this->invoice('30.00');
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $balance->entries()->create([
            'type' => 'applied_reversal',
            'amount' => '30.00',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ]);
        CreditBalance::query()->whereKey($balance->id)->update(['amount' => '30.00']);
        $result = $this->harness()->runBlockedPair(
            $this->cancelOperation($payment->id, 'corrupt first'),
            $this->cancelOperation($payment->id, 'corrupt second'),
            $this->invoiceLockPattern(),
            $this->invoiceLockPattern(),
        );

        $this->assertPhysicalWait($result, $this->invoiceLockPattern());
        $this->assertFalse($result['first']['ok']);
        $this->assertFalse($result['second']['ok']);
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame(1, CreditBalanceEntry::query()->where('type', 'applied_reversal')->where('payment_id', $payment->id)->count());
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id]);
    }

    /** @return callable(): array{cancelled: bool} */
    private function cancelOperation(int $paymentId, string $reason): callable
    {
        return static function () use ($paymentId, $reason): array {
            app(CancelPayment::class)->execute(Payment::query()->findOrFail($paymentId), $reason);

            return ['cancelled' => true];
        };
    }

    /** @return callable(): array{cancelled: bool} */
    private function failingCancelOperation(int $paymentId): callable
    {
        return static function () use ($paymentId): array {
            $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
            $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('injected cancellation failure'));
            app()->instance(InvoicePaymentAllocationWriter::class, $writer);
            app(CancelPayment::class)->execute(Payment::query()->findOrFail($paymentId), 'rolled back');

            return ['cancelled' => true];
        };
    }

    /** @return callable(): array{confirmed: bool} */
    private function confirmOperation(int $paymentId): callable
    {
        return static function () use ($paymentId): array {
            app(ConfirmPayment::class)->execute(Payment::query()->findOrFail($paymentId));

            return ['confirmed' => true];
        };
    }

    /** @return callable(): array{applied_minor: int, payment_id: int|null, reason: string|null} */
    private function applyOperation(int $invoiceId): callable
    {
        return static function () use ($invoiceId): array {
            $result = app(ApplyCreditToInvoice::class)->execute(Invoice::query()->findOrFail($invoiceId));

            return [
                'applied_minor' => $result->appliedAmountMinor,
                'payment_id' => $result->paymentId,
                'reason' => $result->noOpReason,
            ];
        };
    }

    private function invoice(string $total, ?int $companyId = null): Invoice
    {
        if ($companyId === null) {
            $companyId = DB::table('companies')->insertGetId(['name' => 'Cancellation concurrency '.uniqid()]);
            $this->companyIds[] = $companyId;
        }

        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'invoice_number' => 'CANCEL-CONCURRENCY-'.uniqid(),
            'issue_date' => '2026-08-11',
            'due_date' => '2026-08-25',
            'total_amount' => $total,
            'status' => 'issued',
        ]);
        $invoice->lines()->create(['description' => 'Concurrency line', 'amount' => $total]);

        return $invoice;
    }

    private function confirmedPayment(Invoice $invoice, string $amount): Payment
    {
        return app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-11',
            'amount' => $amount,
            'payment_method' => 'transfer',
        ]);
    }

    private function pendingPayment(Invoice $invoice, string $amount): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-11',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]));
    }

    private function balance(Invoice $invoice, string $amount): CreditBalance
    {
        return CreditBalance::query()->create(['company_id' => $invoice->company_id, 'amount' => $amount]);
    }

    private function companyBalance(Invoice $invoice): CreditBalance
    {
        return CreditBalance::query()->where('company_id', $invoice->company_id)->sole();
    }

    private function applyCredit(Invoice $invoice): Payment
    {
        $result = app(ApplyCreditToInvoice::class)->execute($invoice);
        $this->assertTrue($result->applied);

        return Payment::query()->findOrFail($result->paymentId);
    }

    /** @param array<string, mixed> $result */
    private function assertPhysicalWait(array $result, string $pattern): void
    {
        $this->assertNotSame($result['first_connection_id'], $result['second_connection_id']);
        $this->assertMatchesRegularExpression($pattern, $result['waiting_sql']);
        $this->assertNotSame('', $result['paused_sql']);
    }

    private function assertCancelledWithoutAllocations(Payment $payment): void
    {
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->cancelled_at);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
    }

    private function assertLedgerPair(Payment $payment, string $sourceType, string $reversalType, string $amount): void
    {
        foreach ([$sourceType, $reversalType] as $type) {
            $this->assertDatabaseHas('credit_balance_entries', [
                'type' => $type,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'amount' => $amount,
            ]);
            $this->assertSame(1, CreditBalanceEntry::query()->where([
                'type' => $type,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ])->count());
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
        DB::table('payment_allocations')->whereIn('payment_id', $paymentIds)->orWhereIn('invoice_line_id', $lineIds)->delete();
        DB::table('credit_balance_entries')->whereIn('invoice_id', $invoiceIds)->delete();
        DB::table('payments')->whereIn('id', $paymentIds)->delete();
        DB::table('invoice_lines')->whereIn('id', $lineIds)->delete();
        DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        DB::table('credit_balances')->whereIn('company_id', $this->companyIds)->delete();
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

    private function creditBalanceUpdatePattern(): string
    {
        return '/update [`"]?credit_balances[`"]?/is';
    }
}
