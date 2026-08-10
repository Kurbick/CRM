<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DomainQueryRecorder;

class TopUpCancellationLifecycleIntegrationTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_exact_unused_top_up_is_reversed_once(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->confirmedPayment($invoice, '130.00');
        $balance = $this->balance($invoice);
        $topUp = $this->topUpEntry($payment);

        app(CancelPayment::class)->execute($payment, 'Cancel unused overpayment');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame('top_up', $topUp->fresh()->type);
        $this->assertSame(1, $this->reversalCount($payment));
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'credit_balance_id' => $balance->id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_partially_consumed_top_up_is_denied_without_mutation(): void
    {
        [$sourceInvoice, $sourcePayment, $balance] = $this->topUpSource('30.00');
        $downstreamInvoice = $this->invoice('20.00', $sourceInvoice->company_id);
        $downstreamPayment = $this->applyCredit($downstreamInvoice);
        $allocations = PaymentAllocation::query()->get()->map->getRawOriginal()->all();

        $this->expectCancellationFailure($sourcePayment, ValidationException::class);

        $this->assertSame('confirmed', $sourcePayment->fresh()->status);
        $this->assertSame('confirmed', $downstreamPayment->fresh()->status);
        $this->assertSame('10.00', $balance->fresh()->amount);
        $this->assertSame($allocations, PaymentAllocation::query()->get()->map->getRawOriginal()->all());
        $this->assertSame(0, $this->reversalCount($sourcePayment));
    }

    public function test_fully_consumed_top_up_is_denied(): void
    {
        [$sourceInvoice, $sourcePayment, $balance] = $this->topUpSource('30.00');
        $this->applyCredit($this->invoice('30.00', $sourceInvoice->company_id));

        $this->expectCancellationFailure($sourcePayment, ValidationException::class);

        $this->assertSame('confirmed', $sourcePayment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($sourcePayment));
    }

    public function test_multiple_sources_and_misleading_aggregate_balance_are_denied(): void
    {
        $companyId = $this->company();
        $invoiceA = $this->invoice('100.00', $companyId);
        $paymentA = $this->confirmedPayment($invoiceA, '130.00');
        $invoiceB = $this->invoice('100.00', $companyId);
        $this->confirmedPayment($invoiceB, '150.00');
        $balance = $this->balance($invoiceA);
        $this->applyCredit($this->invoice('40.00', $companyId));

        $this->assertSame('40.00', $balance->fresh()->amount);
        $this->expectCancellationFailure($paymentA, ValidationException::class);

        $this->assertSame('confirmed', $paymentA->fresh()->status);
        $this->assertSame('40.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($paymentA));
    }

    public function test_first_of_multiple_unconsumed_top_ups_can_be_reversed_exactly(): void
    {
        $companyId = $this->company();
        $invoiceA = $this->invoice('100.00', $companyId);
        $paymentA = $this->confirmedPayment($invoiceA, '130.00');
        $invoiceB = $this->invoice('100.00', $companyId);
        $paymentB = $this->confirmedPayment($invoiceB, '150.00');
        $balance = $this->balance($invoiceA);

        app(CancelPayment::class)->execute($paymentA, 'Cancel exact source A');

        $this->assertSame('50.00', $balance->fresh()->amount);
        $this->assertSame('cancelled', $paymentA->fresh()->status);
        $this->assertSame('confirmed', $paymentB->fresh()->status);
        $this->assertSame(1, $this->reversalCount($paymentA));
        $this->assertSame(0, $this->reversalCount($paymentB));
    }

    public function test_second_of_multiple_unconsumed_top_ups_can_be_reversed_exactly(): void
    {
        $companyId = $this->company();
        $invoiceA = $this->invoice('100.00', $companyId);
        $paymentA = $this->confirmedPayment($invoiceA, '130.00');
        $invoiceB = $this->invoice('100.00', $companyId);
        $paymentB = $this->confirmedPayment($invoiceB, '150.00');
        $balance = $this->balance($invoiceA);

        app(CancelPayment::class)->execute($paymentB, 'Cancel exact source B');

        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame('confirmed', $paymentA->fresh()->status);
        $this->assertSame('cancelled', $paymentB->fresh()->status);
        $this->assertSame(0, $this->reversalCount($paymentA));
        $this->assertSame(1, $this->reversalCount($paymentB));
    }

    public function test_consumption_before_top_up_does_not_block_new_source_reversal(): void
    {
        $companyId = $this->company();
        $oldSource = $this->invoice('100.00', $companyId);
        $this->confirmedPayment($oldSource, '120.00');
        $this->applyCredit($this->invoice('20.00', $companyId));
        $sourceInvoice = $this->invoice('100.00', $companyId);
        $sourcePayment = $this->confirmedPayment($sourceInvoice, '130.00');
        $balance = $this->balance($sourceInvoice);

        app(CancelPayment::class)->execute($sourcePayment, 'Cancel later unused source');

        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame('cancelled', $sourcePayment->fresh()->status);
        $this->assertSame(1, $this->reversalCount($sourcePayment));
    }

    public function test_later_applied_then_reversed_history_remains_conservatively_blocked(): void
    {
        [$sourceInvoice, $sourcePayment, $balance] = $this->topUpSource('30.00');
        $downstreamInvoice = $this->invoice('20.00', $sourceInvoice->company_id);
        $downstreamPayment = $this->applyCredit($downstreamInvoice);
        app(CancelPayment::class)->execute($downstreamPayment, 'Restore aggregate Credit only');

        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->expectCancellationFailure($sourcePayment, ValidationException::class);

        $this->assertSame('confirmed', $sourcePayment->fresh()->status);
        $this->assertSame(0, $this->reversalCount($sourcePayment));
        $this->assertDatabaseHas('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_later_replenishment_cannot_fund_reversal_of_consumed_source(): void
    {
        $companyId = $this->company();
        $invoiceA = $this->invoice('100.00', $companyId);
        $paymentA = $this->confirmedPayment($invoiceA, '130.00');
        $this->applyCredit($this->invoice('30.00', $companyId));
        $invoiceB = $this->invoice('100.00', $companyId);
        $this->confirmedPayment($invoiceB, '150.00');
        $balance = $this->balance($invoiceA);

        $this->assertSame('50.00', $balance->fresh()->amount);
        $this->expectCancellationFailure($paymentA, ValidationException::class);

        $this->assertSame('50.00', $balance->fresh()->amount);
        $this->assertSame('confirmed', $paymentA->fresh()->status);
        $this->assertSame(0, $this->reversalCount($paymentA));
    }

    public function test_only_exact_top_up_for_selected_payment_is_reversed(): void
    {
        $companyId = $this->company();
        $invoiceA = $this->invoice('100.00', $companyId);
        $paymentA = $this->confirmedPayment($invoiceA, '130.00');
        $invoiceB = $this->invoice('100.00', $companyId);
        $paymentB = $this->confirmedPayment($invoiceB, '150.00');

        app(CancelPayment::class)->execute($paymentB, 'Reverse selected exact source');

        $this->assertSame(0, $this->reversalCount($paymentA));
        $this->assertSame(1, $this->reversalCount($paymentB));
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $paymentA->id,
            'amount' => '30.00',
        ]);
    }

    public function test_legacy_orphan_top_up_is_denied_without_capture(): void
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->rawPayment($invoice, 'confirmed', '130.00');
        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $balance = CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $orphan = $balance->entries()->create([
            'type' => 'top_up',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => null,
        ]);

        $this->expectCancellationFailure($payment, ValidationException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNull($orphan->fresh()->payment_id);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up_reversal']);
    }

    public function test_multiple_exact_top_up_entries_fail_closed(): void
    {
        [$invoice, $payment, $balance] = $this->topUpSource('30.00');
        $balance->entries()->create([
            'type' => 'top_up',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
    }

    public function test_existing_reversal_with_confirmed_payment_fails_closed(): void
    {
        [$invoice, $payment, $balance] = $this->topUpSource('30.00');
        $balance->entries()->create([
            'type' => 'top_up_reversal',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);
        $balance->forceFill(['amount' => '0.00'])->saveQuietly();

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(1, $this->reversalCount($payment));
    }

    public function test_cross_company_top_up_tampering_fails_closed(): void
    {
        [$invoice, $payment, $balance] = $this->topUpSource('30.00');
        $otherInvoice = $this->invoice('100.00');
        $otherBalance = CreditBalance::query()->create([
            'company_id' => $otherInvoice->company_id,
            'amount' => '50.00',
        ]);
        $this->topUpEntry($payment)->forceFill([
            'credit_balance_id' => $otherBalance->id,
        ])->saveQuietly();

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame('50.00', $otherBalance->fresh()->amount);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up_reversal']);
    }

    public function test_writer_failure_rolls_back_top_up_reversal_and_retry_succeeds(): void
    {
        [$invoice, $payment, $balance] = $this->topUpSource('30.00');
        $allocation = PaymentAllocation::query()->firstOrFail()->getRawOriginal();
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')
            ->once()
            ->andThrow(new RuntimeException('Injected top-up cancellation failure.'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);

        try {
            app(CancelPayment::class)->execute($payment, 'Rollback top-up reversal');
            $this->fail('Injected writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected top-up cancellation failure.', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        }

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->cancelled_at);
        $this->assertNull($payment->fresh()->cancel_reason);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
        $this->assertDatabaseHas('payment_allocations', $allocation);

        app(CancelPayment::class)->execute($payment->fresh(), 'Successful top-up retry');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(1, $this->reversalCount($payment));
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_second_cancellation_cannot_decrement_credit_twice(): void
    {
        [, $payment, $balance] = $this->topUpSource('30.00');
        app(CancelPayment::class)->execute($payment, 'First top-up cancellation');
        $cancelledAt = $payment->fresh()->cancelled_at?->toJSON();

        $this->expectCancellationFailure($payment, ValidationException::class);

        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(1, $this->reversalCount($payment));
        $this->assertSame('First top-up cancellation', $payment->fresh()->cancel_reason);
        $this->assertSame($cancelledAt, $payment->fresh()->cancelled_at?->toJSON());
    }

    #[DataProvider('topUpAmountProvider')]
    public function test_top_up_reversal_uses_exact_minor_units(string $topUpAmount): void
    {
        $invoice = $this->invoice('100.00');
        $paymentMinor = 10000 + $this->minor($topUpAmount);
        $payment = $this->confirmedPayment($invoice, $this->decimal($paymentMinor));
        $balance = $this->balance($invoice);

        app(CancelPayment::class)->execute($payment, 'Minor-unit top-up reversal');

        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
            'amount' => $topUpAmount,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function topUpAmountProvider(): array
    {
        return [
            'one cent' => ['0.01'],
            'fractional' => ['10.37'],
            'thirty' => ['30.00'],
            'large valid' => ['999999.99'],
        ];
    }

    #[DataProvider('invalidBalanceProvider')]
    public function test_invalid_or_insufficient_balance_fails_closed(string $corruptBalance): void
    {
        [, $payment, $balance] = $this->topUpSource('30.00');
        $balance->forceFill(['amount' => $corruptBalance])->saveQuietly();

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame($corruptBalance, $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
    }

    /** @return array<string, array{string}> */
    public static function invalidBalanceProvider(): array
    {
        return [
            'negative corruption' => ['-1.00'],
            'insufficient aggregate' => ['29.99'],
        ];
    }

    public function test_credit_funded_reversal_remains_source_specific(): void
    {
        $invoice = $this->invoice('30.00');
        CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $creditPayment = $this->applyCredit($invoice);

        app(CancelPayment::class)->execute($creditPayment, 'Cancel Credit-funded source');

        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied_reversal',
            'payment_id' => $creditPayment->id,
        ]);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $creditPayment->id,
        ]);
    }

    public function test_top_up_guard_reads_are_bounded_for_payments_and_lines(): void
    {
        $one = $this->queryProfile(1, 1);
        $manyPayments = $this->queryProfile(6, 1);
        $manyLines = $this->queryProfile(1, 6);

        $this->assertSame($one['reads'], $manyPayments['reads']);
        $this->assertSame($one['reads'], $manyLines['reads']);
        $this->assertSame($one['tables'], $manyPayments['tables']);
        $this->assertSame($one['tables'], $manyLines['tables']);
        $this->assertNotContains('companies', $one['tables']);
    }

    /** @return array{reads: int, writes: int, tables: list<string>} */
    private function queryProfile(int $paymentCount, int $lineCount): array
    {
        $invoice = $this->invoiceWithLines('600.00', $lineCount);
        $sourcePayment = $this->confirmedPayment($invoice, '700.00');

        for ($index = 1; $index < $paymentCount; $index++) {
            $this->rawPayment($invoice, 'confirmed', '20.00', [
                'payment_date' => sprintf('2026-07-%02d', $index + 1),
            ]);
        }

        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $capture = (new DomainQueryRecorder)->capture(
            fn () => app(CancelPayment::class)->execute($sourcePayment, 'Top-up query profile')
        );
        $reads = count(array_filter(
            $capture['records'],
            static fn (array $record): bool => str_starts_with(strtolower(ltrim($record['sql'])), 'select')
        ));

        return [
            'reads' => $reads,
            'writes' => count($capture['records']) - $reads,
            'tables' => DomainQueryRecorder::tables($capture['records']),
        ];
    }

    /** @return array{Invoice, Payment, CreditBalance} */
    private function topUpSource(string $amount): array
    {
        $invoice = $this->invoice('100.00');
        $payment = $this->confirmedPayment($invoice, $this->decimal(10000 + $this->minor($amount)));

        return [$invoice, $payment, $this->balance($invoice)];
    }

    private function company(): int
    {
        return DB::table('companies')->insertGetId(['name' => 'Company '.uniqid()]);
    }

    private function invoice(string $total, ?int $companyId = null): Invoice
    {
        return $this->invoiceWithLines($total, 1, $companyId);
    }

    private function invoiceWithLines(string $total, int $lineCount, ?int $companyId = null): Invoice
    {
        $companyId ??= $this->company();
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'invoice_number' => 'TOPUP-CANCEL-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => 'issued',
        ]);
        $totalMinor = $this->minor($total);
        $lineMinor = intdiv($totalMinor, $lineCount);

        for ($index = 0; $index < $lineCount; $index++) {
            $amountMinor = $index === $lineCount - 1
                ? $totalMinor - ($lineMinor * ($lineCount - 1))
                : $lineMinor;
            $invoice->lines()->create([
                'description' => 'Top-up cancellation line '.($index + 1),
                'amount' => $this->decimal($amountMinor),
            ]);
        }

        return $invoice;
    }

    private function confirmedPayment(Invoice $invoice, string $amount): Payment
    {
        return app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => '2026-07-20',
            'amount' => $amount,
            'payment_method' => 'transfer',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function rawPayment(
        Invoice $invoice,
        string $status,
        string $amount,
        array $overrides = [],
    ): Payment {
        return Payment::withoutEvents(fn (): Payment => Payment::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-20',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ], $overrides)));
    }

    private function applyCredit(Invoice $invoice): Payment
    {
        $result = app(ApplyCreditToInvoice::class)->execute($invoice);
        $this->assertTrue($result->applied);

        return Payment::query()->findOrFail($result->paymentId);
    }

    private function balance(Invoice $invoice): CreditBalance
    {
        return CreditBalance::query()->where('company_id', $invoice->company_id)->sole();
    }

    private function topUpEntry(Payment $payment): CreditBalanceEntry
    {
        return CreditBalanceEntry::query()
            ->where('type', 'top_up')
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $payment->invoice_id)
            ->sole();
    }

    private function reversalCount(Payment $payment): int
    {
        return CreditBalanceEntry::query()
            ->where('type', 'top_up_reversal')
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $payment->invoice_id)
            ->count();
    }

    /** @param class-string<\Throwable> $exception */
    private function expectCancellationFailure(Payment $payment, string $exception): void
    {
        try {
            app(CancelPayment::class)->execute($payment, 'Expected top-up cancellation conflict');
            $this->fail('Top-up cancellation must fail closed.');
        } catch (\Throwable $throwable) {
            $this->assertInstanceOf($exception, $throwable);
        }
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
