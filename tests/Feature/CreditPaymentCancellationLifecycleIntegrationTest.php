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

class CreditPaymentCancellationLifecycleIntegrationTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_full_credit_payment_cancellation_restores_exact_credit_and_history(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '100.00');
        $payment = $this->applyCredit($invoice);
        $applied = $this->appliedEntry($payment);

        app(CancelPayment::class)->execute($payment, 'Возврат оплаты из Credit Balance');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->cancelled_at);
        $this->assertSame('Возврат оплаты из Credit Balance', $payment->fresh()->cancel_reason);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $balance->fresh()->amount);
        $this->assertSame('applied', $applied->fresh()->type);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied_reversal',
            'credit_balance_id' => $balance->id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up']);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up_reversal']);
    }

    #[DataProvider('decimalAmountProvider')]
    public function test_partial_credit_cancellation_uses_exact_minor_units(
        string $amount,
        int $expectedMinor,
    ): void {
        $invoice = $this->invoice('100.00', 1);
        $balance = $this->balance($invoice, '100.00');
        $payment = $this->applyCredit($invoice, $expectedMinor);

        app(CancelPayment::class)->execute($payment, 'Minor-unit Credit reversal');

        $this->assertSame('100.00', $balance->fresh()->amount);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied_reversal',
            'payment_id' => $payment->id,
            'amount' => $amount,
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    /** @return array<string, array{string, int}> */
    public static function decimalAmountProvider(): array
    {
        return [
            'one cent' => ['0.01', 1],
            'fractional amount' => ['10.37', 1037],
            'partial thirty' => ['30.00', 3000],
        ];
    }

    public function test_mixed_funding_credit_cancellation_restores_only_credit_source(): void
    {
        $invoice = $this->invoice('100.00', 1);
        $balance = $this->balance($invoice, '100.00');
        $creditPayment = $this->applyCredit($invoice, 3000);
        $normalPayment = $this->confirmedPayment($invoice->fresh(), '70.00');

        app(CancelPayment::class)->execute($creditPayment, 'Cancel Credit source only');

        $this->assertSame('cancelled', $creditPayment->fresh()->status);
        $this->assertSame('confirmed', $normalPayment->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('100.00', $balance->fresh()->amount);
        $this->assertSame(7000, $this->allocationTotalMinor($normalPayment));
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $creditPayment->id]);
        $this->assertSame(1, $this->reversalCount($creditPayment));
    }

    public function test_mixed_funding_normal_cancellation_does_not_reverse_credit_source(): void
    {
        $invoice = $this->invoice('100.00', 1);
        $balance = $this->balance($invoice, '100.00');
        $creditPayment = $this->applyCredit($invoice, 3000);
        $normalPayment = $this->confirmedPayment($invoice->fresh(), '70.00');

        app(CancelPayment::class)->execute($normalPayment, 'Cancel normal source only');

        $this->assertSame('confirmed', $creditPayment->fresh()->status);
        $this->assertSame('cancelled', $normalPayment->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->amount);
        $this->assertSame(3000, $this->allocationTotalMinor($creditPayment));
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_pending_reservation_survives_credit_cancellation_and_reapplication(): void
    {
        $invoice = $this->invoice('100.00', 1);
        $pending = $this->payment($invoice, 'pending', '70.00');
        $balance = $this->balance($invoice, '100.00');
        $firstCreditPayment = $this->applyCredit($invoice);

        app(CancelPayment::class)->execute($firstCreditPayment, 'Restore reserved Credit capacity');

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $balance->fresh()->amount);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $pending->id]);

        $secondResult = app(ApplyCreditToInvoice::class)->execute($invoice->fresh());
        $this->assertTrue($secondResult->applied);
        $this->assertSame(3000, $secondResult->appliedAmountMinor);
        $this->assertNotSame($firstCreditPayment->id, $secondResult->paymentId);
        $this->assertSame('70.00', $balance->fresh()->amount);
        $this->assertSame('cancelled', $firstCreditPayment->fresh()->status);
        $this->assertSame('confirmed', Payment::query()->findOrFail($secondResult->paymentId)->status);
    }

    public function test_reapplication_after_reversal_preserves_old_and_new_ownership(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $firstPayment = $this->applyCredit($invoice);
        app(CancelPayment::class)->execute($firstPayment, 'First Credit reversal');

        $secondResult = app(ApplyCreditToInvoice::class)->execute($invoice->fresh());

        $this->assertTrue($secondResult->applied);
        $this->assertNotSame($firstPayment->id, $secondResult->paymentId);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(2, CreditBalanceEntry::query()->where('type', 'applied')->count());
        $this->assertSame(1, CreditBalanceEntry::query()->where('type', 'applied_reversal')->count());
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame('cancelled', $firstPayment->fresh()->status);
        $this->assertSame('confirmed', Payment::query()->findOrFail($secondResult->paymentId)->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_cancelling_one_payment_reverses_only_its_exact_applied_entry(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Shared company '.uniqid()]);
        $firstInvoice = $this->invoice('30.00', 1, $companyId);
        $secondInvoice = $this->invoice('20.00', 1, $companyId);
        $balance = $this->balance($firstInvoice, '100.00');
        $firstPayment = $this->applyCredit($firstInvoice);
        $secondPayment = $this->applyCredit($secondInvoice);
        $orphan = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '5.00',
            'invoice_id' => $secondInvoice->id,
            'payment_id' => null,
        ]);

        app(CancelPayment::class)->execute($secondPayment, 'Reverse exact second entry');

        $this->assertSame('70.00', $balance->fresh()->amount);
        $this->assertSame('confirmed', $firstPayment->fresh()->status);
        $this->assertSame('cancelled', $secondPayment->fresh()->status);
        $this->assertSame(0, $this->reversalCount($firstPayment));
        $this->assertSame(1, $this->reversalCount($secondPayment));
        $this->assertNull($orphan->fresh()->payment_id);
    }

    public function test_legacy_comment_and_orphan_source_are_denied_without_mutation(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '70.00');
        $payment = $this->payment($invoice, 'confirmed', '30.00', [
            'comment' => 'Автоматически применён Credit Balance — legacy',
        ]);
        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $orphan = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => null,
        ]);

        $this->expectCancellationFailure($payment, ValidationException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->amount);
        $this->assertNull($orphan->fresh()->payment_id);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_existing_reversal_with_confirmed_payment_fails_closed(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $balance->entries()->create([
            'type' => 'applied_reversal',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);
        CreditBalance::query()->whereKey($balance->id)->update(['amount' => '30.00']);

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame(1, $this->reversalCount($payment));
    }

    public function test_applied_amount_mismatch_fails_closed_without_restoration(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $this->appliedEntry($payment)->forceFill(['amount' => '29.99'])->saveQuietly();

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
    }

    public function test_multiple_exact_applied_entries_fail_closed(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $balance->entries()->create([
            'type' => 'applied',
            'amount' => '30.00',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
    }

    public function test_cross_company_applied_balance_tampering_fails_closed(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $otherInvoice = $this->invoice('30.00', 1);
        $otherBalance = $this->balance($otherInvoice, '50.00');
        $applied = $this->appliedEntry($payment);
        $applied->forceFill(['credit_balance_id' => $otherBalance->id])->saveQuietly();

        $this->expectCancellationFailure($payment, LogicException::class);

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame('50.00', $otherBalance->fresh()->amount);
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_writer_failure_rolls_back_credit_reversal_and_retry_succeeds_once(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $balance = $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);
        $allocation = PaymentAllocation::query()->firstOrFail()->getRawOriginal();
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')
            ->once()
            ->andThrow(new RuntimeException('Injected Credit cancellation failure.'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);

        try {
            app(CancelPayment::class)->execute($payment, 'Rollback Credit reversal');
            $this->fail('Injected writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected Credit cancellation failure.', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        }

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->cancelled_at);
        $this->assertNull($payment->fresh()->cancel_reason);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('0.00', $balance->fresh()->amount);
        $this->assertSame(0, $this->reversalCount($payment));
        $this->assertDatabaseHas('payment_allocations', $allocation);

        app(CancelPayment::class)->execute($payment->fresh(), 'Successful Credit retry');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('30.00', $balance->fresh()->amount);
        $this->assertSame(1, $this->reversalCount($payment));
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_cancelled_credit_is_excluded_from_source_display_and_breakdown(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Из баланса: 30,00 ₼');
        app(CancelPayment::class)->execute($payment, 'Remove active Credit source');

        $this->get(route('invoices.show', $invoice->fresh()))
            ->assertOk()
            ->assertDontSee('Из баланса: 30,00 ₼')
            ->assertSee('Отменён:');
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
    }

    public function test_credit_reversal_does_not_create_top_up_reversal(): void
    {
        $invoice = $this->invoice('30.00', 1);
        $this->balance($invoice, '30.00');
        $payment = $this->applyCredit($invoice);

        app(CancelPayment::class)->execute($payment, 'Source-specific Credit reversal');

        $this->assertSame(1, $this->reversalCount($payment));
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
        ]);
    }

    public function test_credit_cancellation_reads_are_bounded_for_payments_and_lines(): void
    {
        $one = $this->queryProfile(1, 1);
        $manyPayments = $this->queryProfile(6, 1);
        $manyLines = $this->queryProfile(1, 6);

        $this->assertSame($one['reads'], $manyPayments['reads']);
        $this->assertSame($one['reads'], $manyLines['reads']);
        $this->assertSame($one['tables'], $manyPayments['tables']);
        $this->assertSame($one['tables'], $manyLines['tables']);
        $this->assertNotContains('companies', $one['tables']);
        $this->assertGreaterThan(0, $one['writes']);
        $this->assertGreaterThan(0, $manyPayments['writes']);
        $this->assertGreaterThan(0, $manyLines['writes']);
    }

    /** @return array{reads: int, writes: int, tables: list<string>} */
    private function queryProfile(int $paymentCount, int $lineCount): array
    {
        $invoice = $this->invoice('600.00', $lineCount);
        $this->balance($invoice, '100.00');
        $creditPayment = $this->applyCredit($invoice, 10000);

        for ($index = 1; $index < $paymentCount; $index++) {
            $this->payment($invoice, 'confirmed', '20.00', [
                'payment_date' => sprintf('2026-07-%02d', $index + 1),
            ]);
        }

        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $capture = (new DomainQueryRecorder)->capture(
            fn () => app(CancelPayment::class)->execute($creditPayment, 'Credit query profile')
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

    private function invoice(string $total, int $lineCount, ?int $companyId = null): Invoice
    {
        $companyId ??= DB::table('companies')->insertGetId(['name' => 'Company '.uniqid()]);
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'invoice_number' => 'CREDIT-CANCEL-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => 'issued',
        ]);
        $totalMinor = (int) round((float) $total * 100);
        $lineMinor = intdiv($totalMinor, $lineCount);

        for ($index = 0; $index < $lineCount; $index++) {
            $amountMinor = $index === $lineCount - 1
                ? $totalMinor - ($lineMinor * ($lineCount - 1))
                : $lineMinor;
            $invoice->lines()->create([
                'description' => 'Credit cancellation line '.($index + 1),
                'amount' => sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100),
            ]);
        }

        return $invoice;
    }

    private function balance(Invoice $invoice, string $amount): CreditBalance
    {
        return CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => $amount,
        ]);
    }

    private function applyCredit(Invoice $invoice, ?int $requestedMinor = null): Payment
    {
        $result = app(ApplyCreditToInvoice::class)->execute($invoice, $requestedMinor);
        $this->assertTrue($result->applied);

        return Payment::query()->findOrFail($result->paymentId);
    }

    private function confirmedPayment(Invoice $invoice, string $amount): Payment
    {
        return app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => '2026-07-21',
            'amount' => $amount,
            'payment_method' => 'transfer',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payment(
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

    private function appliedEntry(Payment $payment): CreditBalanceEntry
    {
        return CreditBalanceEntry::query()
            ->where('type', 'applied')
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $payment->invoice_id)
            ->sole();
    }

    private function reversalCount(Payment $payment): int
    {
        return CreditBalanceEntry::query()
            ->where('type', 'applied_reversal')
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $payment->invoice_id)
            ->count();
    }

    private function allocationTotalMinor(Payment $payment): int
    {
        return (int) round((float) $payment->allocations()->sum('amount') * 100);
    }

    /** @param class-string<\Throwable> $exception */
    private function expectCancellationFailure(Payment $payment, string $exception): void
    {
        try {
            app(CancelPayment::class)->execute($payment, 'Expected cancellation conflict');
            $this->fail('Credit cancellation must fail closed.');
        } catch (\Throwable $throwable) {
            $this->assertInstanceOf($exception, $throwable);
        }
    }
}
