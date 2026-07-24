<?php

namespace Tests\Feature;

use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\AuthenticatedTestCase as TestCase;

class PaymentAllocationLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_pending_payment_does_not_create_allocation(): void
    {
        [$invoice] = $this->invoice([100]);

        $this->storePayment($invoice, 'pending', 40);

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_creating_confirmed_payment_creates_allocation(): void
    {
        [$invoice, [$line]] = $this->invoice([100]);

        $this->storePayment($invoice, 'confirmed', 40);
        $payment = $invoice->payments()->firstOrFail();

        $this->assertAllocation($payment, $line, '40.00');
    }

    public function test_confirming_pending_payment_creates_allocation(): void
    {
        [$invoice, [$line]] = $this->invoice([100]);
        $this->storePayment($invoice, 'pending', 40);
        $payment = $invoice->payments()->firstOrFail();

        $this->patch(route('payments.confirm', $payment))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertAllocation($payment, $line, '40.00');
    }

    public function test_repeated_confirmation_is_rejected_without_changing_allocation(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'pending', 40);
        $payment = $invoice->payments()->firstOrFail();
        $this->patch(route('payments.confirm', $payment));
        $before = PaymentAllocation::query()->firstOrFail()->getRawOriginal();

        $this->patch(route('payments.confirm', $payment))
            ->assertSessionHasErrors('payment_confirm');

        $this->assertSame($before, PaymentAllocation::query()->firstOrFail()->getRawOriginal());
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_confirmed_partial_payment_allocation_is_limited_to_payment_amount(): void
    {
        [$invoice, [$line]] = $this->invoice([100]);

        $this->storePayment($invoice, 'confirmed', 25);

        $this->assertAllocation($invoice->payments()->firstOrFail(), $line, '25.00');
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    public function test_confirmed_payment_is_distributed_between_two_lines_fifo(): void
    {
        [$invoice, [$oldLine, $newLine]] = $this->invoice([100, 100]);

        $this->storePayment($invoice, 'confirmed', 130);
        $payment = $invoice->payments()->firstOrFail();

        $this->assertAllocation($payment, $oldLine, '100.00');
        $this->assertAllocation($payment, $newLine, '30.00');
    }

    public function test_two_confirmed_payments_follow_payment_date_and_id_order(): void
    {
        [$invoice, [$oldLine, $newLine]] = $this->invoice([100, 100]);
        $this->storePayment($invoice, 'confirmed', 130, '2026-07-01');
        $this->storePayment($invoice->fresh(), 'confirmed', 70, '2026-07-02');
        [$first, $second] = $invoice->payments()->orderBy('id')->get()->all();

        $this->assertAllocation($first, $oldLine, '100.00');
        $this->assertAllocation($first, $newLine, '30.00');
        $this->assertAllocation($second, $newLine, '70.00');
    }

    public function test_confirming_earlier_payment_redistributes_existing_allocations(): void
    {
        [$invoice, [$oldLine, $newLine]] = $this->invoice([100, 100]);
        $this->storePayment($invoice, 'pending', 100, '2026-06-01');
        $early = $invoice->payments()->firstOrFail();
        $this->storePayment($invoice, 'confirmed', 100, '2026-07-01');
        $late = $invoice->payments()->where('status', 'confirmed')->firstOrFail();
        $this->assertAllocation($late, $oldLine, '100.00');

        $this->patch(route('payments.confirm', $early));

        $this->assertAllocation($early, $oldLine, '100.00');
        $this->assertAllocation($late, $newLine, '100.00');
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $late->id,
            'invoice_line_id' => $oldLine->id,
        ]);
    }

    public function test_cancelling_pending_payment_leaves_allocations_empty(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'pending', 40);
        $payment = $invoice->payments()->firstOrFail();

        $this->cancelPayment($payment);

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_cancelling_confirmed_payment_deletes_its_allocations(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'confirmed', 40);
        $payment = $invoice->payments()->firstOrFail();

        $this->cancelPayment($payment);

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_cancelling_first_confirmed_payment_moves_second_to_oldest_line(): void
    {
        [$invoice, [$oldLine, $newLine]] = $this->invoice([100, 100]);
        $this->storePayment($invoice, 'confirmed', 100, '2026-07-01');
        $this->storePayment($invoice->fresh(), 'confirmed', 100, '2026-07-02');
        [$first, $second] = $invoice->payments()->orderBy('id')->get()->all();

        $this->cancelPayment($first);

        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $first->id]);
        $this->assertAllocation($second, $oldLine, '100.00');
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $second->id,
            'invoice_line_id' => $newLine->id,
        ]);
    }

    public function test_cancelling_unused_overpayment_reverses_credit_and_allocations(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'confirmed', 125);
        $payment = $invoice->payments()->firstOrFail();

        $this->cancelPayment($payment);

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
            'amount' => '25.00',
        ]);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
        ]);
    }

    public function test_used_overpayment_blocks_cancellation_without_changing_allocations(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'confirmed', 125);
        $payment = $invoice->payments()->firstOrFail();
        $before = PaymentAllocation::query()->get()->map->getRawOriginal()->all();
        [$otherInvoice] = $this->invoice([100], $invoice->company_id);
        $balance = CreditBalance::query()->where('company_id', $invoice->company_id)->firstOrFail();
        $balance->apply(25, $otherInvoice);

        $this->cancelPayment($payment)->assertSessionHasErrors('cancel_reason');

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame($before, PaymentAllocation::query()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
        ]);
    }

    public function test_credit_balance_payment_created_during_issue_gets_allocation(): void
    {
        [$invoice, [$line]] = $this->draftInvoiceWithCredit(30);

        $this->post(route('invoices.issue', $invoice))
            ->assertSessionDoesntHaveErrors();

        $payment = $invoice->payments()->where('status', 'confirmed')->firstOrFail();
        $this->assertAllocation($payment, $line, '30.00');
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied',
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);
    }

    public function test_overpayment_allocation_is_capped_at_line_total(): void
    {
        [$invoice, [$line]] = $this->invoice([100]);

        $this->storePayment($invoice, 'confirmed', 150);
        $payment = $invoice->payments()->firstOrFail();

        $this->assertAllocation($payment, $line, '100.00');
        $this->assertSame(100.0, (float) PaymentAllocation::query()->sum('amount'));
    }

    public function test_lifecycle_event_backfills_old_confirmed_payments_for_same_invoice(): void
    {
        [$invoice, [$oldLine, $newLine]] = $this->invoice([100, 100]);
        $oldPayment = Payment::withoutEvents(fn() => Payment::create([
            ...$this->paymentAttributes($invoice, 'confirmed', 100, '2026-07-01'),
        ]));
        $this->assertDatabaseCount('payment_allocations', 0);

        $this->storePayment($invoice, 'confirmed', 50, '2026-07-02');
        $newPayment = $invoice->payments()
            ->where('id', '!=', $oldPayment->id)
            ->firstOrFail();

        $this->assertAllocation($oldPayment, $oldLine, '100.00');
        $this->assertAllocation($newPayment, $newLine, '50.00');
    }

    public function test_payment_lifecycle_is_isolated_between_invoices(): void
    {
        [$firstInvoice, [$firstLine]] = $this->invoice([100]);
        [$secondInvoice, [$secondLine]] = $this->invoice([100]);
        $this->storePayment($secondInvoice, 'confirmed', 30);
        $secondPayment = $secondInvoice->payments()->firstOrFail();
        $before = PaymentAllocation::query()->where('payment_id', $secondPayment->id)->firstOrFail()->getRawOriginal();

        $this->storePayment($firstInvoice, 'confirmed', 40);

        $this->assertAllocation($firstInvoice->payments()->firstOrFail(), $firstLine, '40.00');
        $this->assertAllocation($secondPayment, $secondLine, '30.00');
        $this->assertSame(
            $before,
            PaymentAllocation::query()->where('payment_id', $secondPayment->id)->firstOrFail()->getRawOriginal()
        );
    }

    public function test_writer_exception_rolls_back_confirmed_store(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->bindFailingWriter();
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);

        try {
            $this->storePayment($invoice, 'confirmed', 125);
        } finally {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('credit_balances', 0);
            $this->assertSame('issued', $invoice->fresh()->status);
        }
    }

    public function test_writer_exception_rolls_back_pending_confirmation(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'pending', 40);
        $payment = $invoice->payments()->firstOrFail();
        $this->bindFailingWriter();
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);

        try {
            $this->patch(route('payments.confirm', $payment));
        } finally {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('issued', $invoice->fresh()->status);
            $this->assertDatabaseCount('payment_allocations', 0);
        }
    }

    public function test_writer_exception_rolls_back_confirmed_cancellation_and_reversal(): void
    {
        [$invoice] = $this->invoice([100]);
        $this->storePayment($invoice, 'confirmed', 125);
        $payment = $invoice->payments()->firstOrFail();
        $allocationBefore = PaymentAllocation::query()->firstOrFail()->getRawOriginal();
        $balanceBefore = CreditBalance::query()->firstOrFail()->getRawOriginal();
        $this->bindFailingWriter();
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);

        try {
            $this->cancelPayment($payment);
        } finally {
            $payment->refresh();
            $this->assertSame('confirmed', $payment->status);
            $this->assertNull($payment->cancelled_at);
            $this->assertNull($payment->cancel_reason);
            $this->assertSame('paid', $invoice->fresh()->status);
            $this->assertSame($allocationBefore, PaymentAllocation::query()->firstOrFail()->getRawOriginal());
            $this->assertSame($balanceBefore, CreditBalance::query()->firstOrFail()->getRawOriginal());
            $this->assertDatabaseMissing('credit_balance_entries', ['type' => 'top_up_reversal']);
        }
    }

    /** @return array{Invoice, list<\App\Models\InvoiceLine>} */
    private function invoice(array $amounts, ?int $companyId = null): array
    {
        $companyId ??= DB::table('companies')->insertGetId(['name' => 'Lifecycle '.uniqid()]);
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'invoice_number' => 'ALLOC-LIFE-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => array_sum($amounts),
            'status' => 'issued',
        ]);
        $lines = [];

        foreach ($amounts as $index => $amount) {
            $lines[] = $invoice->lines()->create([
                'description' => 'Period '.($index + 1),
                'amount' => $amount,
                'period_start' => sprintf('2026-%02d-01', $index + 5),
                'period_end' => sprintf('2026-%02d-28', $index + 5),
            ]);
        }

        return [$invoice, $lines];
    }

    /** @return array{Invoice, list<\App\Models\InvoiceLine>} */
    private function draftInvoiceWithCredit(float $credit): array
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Credit issue '.uniqid()]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CREDIT-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'CREDIT-INV-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $line = $invoice->lines()->create([
            'description' => 'Manual service',
            'amount' => 100,
        ]);
        CreditBalance::create(['company_id' => $companyId, 'amount' => $credit]);

        return [$invoice, [$line]];
    }

    private function storePayment(
        Invoice $invoice,
        string $status,
        float $amount,
        string $date = '2026-07-01'
    ): TestResponse {
        return $this->post(route('payments.store', $invoice), [
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ])->assertSessionDoesntHaveErrors();
    }

    private function cancelPayment(Payment $payment): TestResponse
    {
        return $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Проверка отмены распределения',
        ]);
    }

    private function paymentAttributes(
        Invoice $invoice,
        string $status,
        float $amount,
        string $date
    ): array {
        return [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ];
    }

    private function assertAllocation(
        Payment $payment,
        \App\Models\InvoiceLine $line,
        string $amount
    ): void
    {
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'invoice_line_id' => $line->id,
            'amount' => $amount,
        ]);
    }

    private function bindFailingWriter(): void
    {
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')
            ->once()
            ->andThrow(new RuntimeException('Allocation synchronization failed.'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
    }
}
