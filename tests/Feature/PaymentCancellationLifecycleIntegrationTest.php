<?php

namespace Tests\Feature;

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\DomainQueryRecorder;

class PaymentCancellationLifecycleIntegrationTest extends FinancialTestCase
{
    use RefreshDatabase;

    public function test_action_cancels_confirmed_payment_and_reflows_remaining_payment(): void
    {
        $invoice = $this->invoice([60, 40]);
        $first = $this->confirmedPayment($invoice, 60, '2026-07-01');
        $second = $this->confirmedPayment($invoice->fresh(), 40, '2026-07-02');

        $cancelled = app(CancelPayment::class)->execute($first, 'Банковский платёж отозван');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Банковский платёж отозван', $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $first->id]);
        $this->assertSame(4000, $this->allocationTotalMinor($second));
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_action_cancels_pending_payment_without_financial_side_effects(): void
    {
        $invoice = $this->invoice([100]);
        $payment = $this->payment($invoice, 'pending', 70);

        app(CancelPayment::class)->execute($payment, 'Ошибочный ожидающий платёж');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('credit_balances', 0);
    }

    public function test_action_rejects_duplicate_cancellation_without_overwriting_metadata(): void
    {
        $invoice = $this->invoice([100]);
        $payment = $this->payment($invoice, 'pending', 70);
        $first = app(CancelPayment::class)->execute($payment, 'Первая подтверждённая причина');
        $cancelledAt = $first->cancelled_at?->toJSON();

        try {
            app(CancelPayment::class)->execute($payment, 'Повторная причина отмены');
            $this->fail('Repeated cancellation must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancel_reason', $exception->errors());
        }

        $payment->refresh();
        $this->assertSame('cancelled', $payment->status);
        $this->assertSame('Первая подтверждённая причина', $payment->cancel_reason);
        $this->assertSame($cancelledAt, $payment->cancelled_at?->toJSON());
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_action_rejects_stale_cross_invoice_locator_without_mutation(): void
    {
        $invoice = $this->invoice([100]);
        $otherInvoice = $this->invoice([100]);
        $payment = $this->payment($invoice, 'pending', 70);
        $payment->invoice_id = $otherInvoice->id;

        try {
            app(CancelPayment::class)->execute($payment, 'Cross-invoice cancellation');
            $this->fail('A stale cross-invoice locator must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Платёж не принадлежит заблокированному инвойсу.'],
                $exception->errors()['cancel_reason']
            );
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('issued', $otherInvoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_credit_funded_payment_remains_blocked_without_mutation(): void
    {
        $invoice = $this->invoice([100]);
        $payment = $this->payment($invoice, 'confirmed', 30, '2026-07-01', [
            'comment' => 'Credit-funded payment',
        ]);
        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $balance = CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '70.00',
        ]);
        $entry = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '30.00',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ]);
        $beforeAllocations = PaymentAllocation::query()->get()->map->getRawOriginal()->all();

        try {
            app(CancelPayment::class)->execute($payment, 'Попытка обычной отмены Credit');
            $this->fail('Credit-funded cancellation must fail in Stage 5D1.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Автоматическое применение Credit Balance нельзя отменить как обычный платёж.'],
                $exception->errors()['cancel_reason']
            );
        }

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('70.00', $balance->fresh()->amount);
        $this->assertSame('applied', $entry->fresh()->type);
        $this->assertSame($beforeAllocations, PaymentAllocation::query()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'applied_reversal']);
    }

    public function test_unused_top_up_reversal_is_owned_by_action(): void
    {
        $invoice = $this->invoice([100]);
        $payment = $this->confirmedPayment($invoice, 125);

        app(CancelPayment::class)->execute($payment, 'Возврат ошибочной переплаты');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
            'amount' => '25.00',
        ]);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
        ]);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_writer_failure_rolls_back_full_cancellation_and_retry_succeeds_once(): void
    {
        $invoice = $this->invoice([100]);
        $payment = $this->confirmedPayment($invoice, 125);
        $allocation = PaymentAllocation::query()->firstOrFail()->getRawOriginal();
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')
            ->once()
            ->andThrow(new RuntimeException('Injected cancellation writer failure.'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);

        try {
            app(CancelPayment::class)->execute($payment, 'Rollback cancellation');
            $this->fail('Injected writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected cancellation writer failure.', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        }

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->cancelled_at);
        $this->assertNull($payment->fresh()->cancel_reason);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', $allocation);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '25.00',
        ]);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
        ]);

        app(CancelPayment::class)->execute($payment->fresh(), 'Successful retry');

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, DB::table('credit_balance_entries')
            ->where('type', 'top_up_reversal')
            ->where('payment_id', $payment->id)
            ->count());
    }

    public function test_cancellation_reads_are_bounded_for_one_and_six_payments_and_lines(): void
    {
        $one = $this->queryProfile(paymentCount: 1, lineCount: 1);
        $manyPayments = $this->queryProfile(paymentCount: 6, lineCount: 1);
        $manyLines = $this->queryProfile(paymentCount: 1, lineCount: 6);

        $this->assertSame($one['reads'], $manyPayments['reads']);
        $this->assertSame($one['reads'], $manyLines['reads']);
        $this->assertSame($one['tables'], $manyPayments['tables']);
        $this->assertSame($one['tables'], $manyLines['tables']);
        $this->assertGreaterThanOrEqual($one['writes'], $manyPayments['writes']);
        $this->assertGreaterThanOrEqual($one['writes'], $manyLines['writes']);
        $this->assertNotContains('companies', $one['tables']);
    }

    public function test_web_controller_delegates_and_api_has_no_update_or_destroy_route(): void
    {
        $webSource = file_get_contents(app_path('Http/Controllers/Web/PaymentController.php'));
        $apiSource = file_get_contents(app_path('Http/Controllers/PaymentController.php'));
        $actionSource = file_get_contents(app_path('Actions/Payments/CancelPayment.php'));

        $this->assertStringContainsString('$this->cancelPayment->execute(', $webSource);
        $this->assertStringNotContainsString('lockForUpdate()', $this->methodSource($webSource, 'public function cancel(', 'private function mutationRedirect('));
        $this->assertStringNotContainsString('function destroy(', $apiSource);
        $this->assertStringNotContainsString('function update(', $apiSource);
        $this->assertNull(Route::getRoutes()->getByName('api.payments.destroy'));
        $this->assertNull(Route::getRoutes()->getByName('api.payments.update'));
        $this->assertStringContainsString('DB::transaction(', $actionSource);
        $this->assertLessThan(
            strpos($actionSource, 'Payment::query()'),
            strpos($actionSource, 'Invoice::query()')
        );
    }

    /** @return array{reads: int, writes: int, tables: list<string>} */
    private function queryProfile(int $paymentCount, int $lineCount): array
    {
        $lineAmount = intdiv(600, $lineCount);
        $invoice = $this->invoice(array_fill(0, $lineCount, $lineAmount));
        $payments = [];

        for ($index = 0; $index < $paymentCount; $index++) {
            $payments[] = $this->payment(
                $invoice,
                'confirmed',
                intdiv(600, $paymentCount),
                sprintf('2026-07-%02d', $index + 1)
            );
        }

        app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        $invoice->forceFill(['status' => 'paid'])->save();
        $capture = (new DomainQueryRecorder)->capture(
            fn () => app(CancelPayment::class)->execute($payments[0], 'Query profile cancellation')
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

    private function invoice(array $lineAmounts): Invoice
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Company '.uniqid()]);
        $invoice = Invoice::query()->create([
            'company_id' => $companyId,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => array_sum($lineAmounts),
            'status' => 'issued',
        ]);

        foreach ($lineAmounts as $index => $amount) {
            $invoice->lines()->create([
                'description' => 'Line '.($index + 1),
                'amount' => $amount,
            ]);
        }

        return $invoice;
    }

    private function confirmedPayment(Invoice $invoice, int $amount, string $date = '2026-07-01'): Payment
    {
        return app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => $date,
            'amount' => (string) $amount,
            'payment_method' => 'transfer',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payment(
        Invoice $invoice,
        string $status,
        int $amount,
        string $date = '2026-07-01',
        array $overrides = []
    ): Payment {
        return Payment::withoutEvents(fn (): Payment => Payment::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ], $overrides)));
    }

    private function allocationTotalMinor(Payment $payment): int
    {
        return (int) round((float) $payment->allocations()->sum('amount') * 100);
    }

    private function methodSource(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition);

        $this->assertNotFalse($startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
