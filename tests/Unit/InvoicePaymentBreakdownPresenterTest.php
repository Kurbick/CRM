<?php

namespace Tests\Unit;

use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentBreakdownPresenter;
use Illuminate\Support\Collection;
use LogicException;
use Tests\TestCase;

class InvoicePaymentBreakdownPresenterTest extends TestCase
{
    private InvoicePaymentBreakdownPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new InvoicePaymentBreakdownPresenter();
    }

    public function test_unpaid_lines_are_presented_without_allocations(): void
    {
        $invoice = $this->invoice([
            $this->line(11, '100.00', description: 'Разработка'),
        ]);

        $result = $this->presenter->present($invoice);

        $this->assertSame('0.00', $result['lineRows'][0]['paid_amount']);
        $this->assertSame('100.00', $result['lineRows'][0]['remaining_amount']);
        $this->assertSame('unpaid', $result['lineRows'][0]['payment_state']);
    }

    public function test_partially_paid_and_fully_paid_lines_are_derived_from_saved_allocations(): void
    {
        $lines = [$this->line(11, '100.00'), $this->line(12, '80.00')];
        $payment = $this->payment(21, '130.00', [
            $this->allocation(31, 21, 11, '100.00'),
            $this->allocation(32, 21, 12, '30.00'),
        ]);

        $rows = $this->presenter->present($this->invoice($lines, [$payment]))['lineRows'];

        $this->assertSame(['paid', 'partially_paid'], array_column($rows, 'payment_state'));
        $this->assertSame(['0.00', '50.00'], array_column($rows, 'remaining_amount'));
    }

    public function test_one_payment_can_cover_multiple_lines_and_multiple_payments_can_cover_one_line(): void
    {
        $lines = [$this->line(11, '100.00'), $this->line(12, '30.00')];
        $payments = [
            $this->payment(21, '60.00', [$this->allocation(31, 21, 11, '60.00')], '2026-07-01'),
            $this->payment(22, '70.00', [
                $this->allocation(32, 22, 11, '40.00'),
                $this->allocation(33, 22, 12, '30.00'),
            ], '2026-07-02'),
        ];

        $result = $this->presenter->present($this->invoice($lines, $payments));

        $this->assertSame(2, $result['lineRows'][0]['allocations_count']);
        $this->assertCount(2, $result['paymentRows'][0]['allocations']);
        $this->assertSame('130.00', $result['totals']['allocated_total']);
    }

    public function test_subscription_order_and_manual_rows_have_labels_and_fifo_display_order(): void
    {
        $invoice = $this->invoice([
            $this->line(30, '1.00', description: 'Manual'),
            $this->line(20, '1.00', subscriptionId: 5, periodStart: '2026-08-01', periodEnd: '2026-08-31'),
            $this->line(10, '1.00', orderId: 7),
            $this->line(15, '1.00', subscriptionId: 6, periodStart: '2026-07-01', periodEnd: '2026-07-31'),
        ]);

        $rows = $this->presenter->present($invoice)['lineRows'];

        $this->assertSame([15, 20, 10, 30], array_column($rows, 'id'));
        $this->assertSame(['subscription', 'subscription', 'order', 'manual'], array_column($rows, 'type'));
        $this->assertSame('01/07/2026 — 31/07/2026', $rows[0]['period_label']);
    }

    public function test_applied_and_unallocated_payment_amounts_are_exact(): void
    {
        $line = $this->line(11, '100.00');
        $payment = $this->payment(21, '130.00', [$this->allocation(31, 21, 11, '100.00')]);

        $result = $this->presenter->present($this->invoice([$line], [$payment]));

        $this->assertSame('100.00', $result['paymentRows'][0]['applied_amount']);
        $this->assertSame('30.00', $result['paymentRows'][0]['unallocated_amount']);
        $this->assertSame('30.00', $result['totals']['unallocated_total']);
    }

    public function test_pending_and_cancelled_payments_do_not_reduce_lines(): void
    {
        $payments = [
            $this->payment(21, '25.00', [], status: 'pending'),
            $this->payment(22, '25.00', [], status: 'cancelled'),
        ];

        $result = $this->presenter->present($this->invoice([$this->line(11, '50.00')], $payments));

        $this->assertSame('0.00', $result['totals']['allocated_total']);
        $this->assertSame('0.00', $result['totals']['confirmed_payments_total']);
        $this->assertSame('50.00', $result['lineRows'][0]['remaining_amount']);
    }

    public function test_credit_balance_uses_applied_entry_not_comment(): void
    {
        $entry = new CreditBalanceEntry(['type' => 'applied', 'amount' => '10.00']);
        $creditPayment = $this->payment(21, '10.00', [$this->allocation(31, 21, 11, '10.00')]);
        $creditPayment->setRelation('creditBalanceEntries', new Collection([$entry]));
        $commentPayment = $this->payment(22, '5.00', [$this->allocation(32, 22, 11, '5.00')]);
        $commentPayment->comment = 'Автоматически применён Credit Balance';

        $rows = $this->presenter->present(
            $this->invoice([$this->line(11, '15.00')], [$creditPayment, $commentPayment])
        )['paymentRows'];

        $byId = collect($rows)->keyBy('id');
        $this->assertTrue($byId[21]['is_credit_balance']);
        $this->assertFalse($byId[22]['is_credit_balance']);
    }

    public function test_minor_unit_precision_is_preserved(): void
    {
        $payment = $this->payment(21, '0.03', [
            $this->allocation(31, 21, 11, '0.01'),
            $this->allocation(32, 21, 12, '0.02'),
        ]);

        $result = $this->presenter->present($this->invoice([
            $this->line(11, '0.01'),
            $this->line(12, '0.02'),
        ], [$payment]));

        $this->assertSame('0.03', $result['totals']['allocated_total']);
        $this->assertSame('0.00', $result['totals']['remaining_total']);
    }

    public function test_cross_invoice_allocation_is_rejected(): void
    {
        $payment = $this->payment(21, '10.00', [$this->allocation(31, 21, 99, '10.00')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outside the current invoice');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_allocation_over_payment_amount_is_rejected(): void
    {
        $payment = $this->payment(21, '9.00', [$this->allocation(31, 21, 11, '10.00')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exceed its amount');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_non_positive_allocation_is_rejected(): void
    {
        $payment = $this->payment(21, '10.00', [$this->allocation(31, 21, 11, '0.00')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be positive');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_allocation_attached_to_another_payment_is_rejected(): void
    {
        $payment = $this->payment(21, '10.00', [$this->allocation(31, 22, 11, '10.00')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('another payment');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_allocation_over_line_amount_is_rejected(): void
    {
        $payment = $this->payment(21, '11.00', [$this->allocation(31, 21, 11, '11.00')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exceed its amount');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_pending_payment_with_allocation_is_rejected(): void
    {
        $this->assertNonConfirmedAllocationIsRejected('pending');
    }

    public function test_cancelled_payment_with_allocation_is_rejected(): void
    {
        $this->assertNonConfirmedAllocationIsRejected('cancelled');
    }

    private function assertNonConfirmedAllocationIsRejected(string $status): void
    {
        $payment = $this->payment(
            21,
            '10.00',
            [$this->allocation(31, 21, 11, '10.00')],
            status: $status
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has current allocations');
        $this->presenter->present($this->invoice([$this->line(11, '10.00')], [$payment]));
    }

    public function test_presenter_does_not_mutate_models_and_is_deterministic(): void
    {
        $line = $this->line(11, '10.00');
        $payment = $this->payment(21, '10.00', [$this->allocation(31, 21, 11, '10.00')]);
        $invoice = $this->invoice([$line], [$payment]);
        $before = [
            $invoice->getAttributes(),
            $line->getAttributes(),
            $payment->getAttributes(),
            $payment->allocations->first()->getAttributes(),
        ];

        $first = $this->presenter->present($invoice);
        $second = $this->presenter->present($invoice);

        $this->assertSame($first, $second);
        $this->assertSame($before, [
            $invoice->getAttributes(),
            $line->getAttributes(),
            $payment->getAttributes(),
            $payment->allocations->first()->getAttributes(),
        ]);
    }

    public function test_empty_payment_history_has_zero_counts_and_no_latest_payment(): void
    {
        $result = $this->presenter->present($this->invoice([$this->line(11, '10.00')]));

        $this->assertSame(0, $result['payments_count']);
        $this->assertSame(0, $result['pending_payments_count']);
        $this->assertSame(0, $result['confirmed_payments_count']);
        $this->assertSame(0, $result['cancelled_payments_count']);
        $this->assertNull($result['latest_payment']);
    }

    public function test_payment_summary_counts_statuses_without_changing_financial_totals(): void
    {
        $line = $this->line(11, '10.00');
        $payments = [
            $this->payment(21, '1.00', [], status: 'cancelled'),
            $this->payment(22, '2.00', [], status: 'pending'),
            $this->payment(23, '10.00', [$this->allocation(31, 23, 11, '10.00')]),
            $this->payment(24, '1.00', [], status: 'cancelled'),
        ];

        $result = $this->presenter->present($this->invoice([$line], $payments));

        $this->assertSame(4, $result['payments_count']);
        $this->assertSame(1, $result['pending_payments_count']);
        $this->assertSame(1, $result['confirmed_payments_count']);
        $this->assertSame(2, $result['cancelled_payments_count']);
        $this->assertSame('10.00', $result['totals']['allocated_total']);
        $this->assertSame('0.00', $result['totals']['remaining_total']);
        $this->assertArrayNotHasKey('hidden_by_default', $result['paymentRows'][0]);
    }

    public function test_latest_payment_and_full_rows_use_date_desc_then_id_desc(): void
    {
        $payments = [
            $this->payment(30, '1.00', [], '2026-07-19', 'cancelled'),
            $this->payment(21, '1.00', [], '2026-07-20', 'cancelled'),
            $this->payment(22, '1.00', [], '2026-07-20', 'cancelled'),
        ];

        $result = $this->presenter->present(
            $this->invoice([$this->line(11, '10.00')], $payments)
        );

        $this->assertSame([22, 21, 30], array_column($result['paymentRows'], 'id'));
        $this->assertSame(22, $result['latest_payment']['id']);
        $this->assertSame('2026-07-20', $result['latest_payment']['payment_date']);
        $this->assertSame('Отменён', $result['latest_payment']['status_label']);
    }

    private function invoice(array $lines, array $payments = [], int $id = 1): Invoice
    {
        $invoice = new Invoice([
            'total_amount' => $this->sumLineAmounts($lines),
            'status' => 'issued',
        ]);
        $invoice->id = $id;
        $invoice->exists = true;
        $invoice->setRelation('lines', new Collection($lines));
        $invoice->setRelation('payments', new Collection($payments));

        return $invoice;
    }

    private function line(
        int $id,
        string $amount,
        ?string $description = null,
        ?int $subscriptionId = null,
        ?int $orderId = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        int $invoiceId = 1
    ): InvoiceLine {
        $line = new InvoiceLine([
            'invoice_id' => $invoiceId,
            'description' => $description ?? "Line {$id}",
            'amount' => $amount,
            'subscription_id' => $subscriptionId,
            'order_id' => $orderId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
        $line->id = $id;
        $line->exists = true;

        return $line;
    }

    private function payment(
        int $id,
        string $amount,
        array $allocations,
        string $paymentDate = '2026-07-20',
        string $status = 'confirmed',
        int $invoiceId = 1
    ): Payment {
        $payment = new Payment([
            'invoice_id' => $invoiceId,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]);
        $payment->id = $id;
        $payment->exists = true;
        $payment->setRelation('allocations', new Collection($allocations));
        $payment->setRelation('creditBalanceEntries', new Collection());

        return $payment;
    }

    private function allocation(
        int $id,
        int $paymentId,
        int $lineId,
        string $amount
    ): PaymentAllocation {
        $allocation = new PaymentAllocation([
            'payment_id' => $paymentId,
            'invoice_line_id' => $lineId,
            'amount' => $amount,
        ]);
        $allocation->id = $id;
        $allocation->exists = true;

        return $allocation;
    }

    private function sumLineAmounts(array $lines): string
    {
        $minor = 0;
        foreach ($lines as $line) {
            [$whole, $fraction] = array_pad(explode('.', (string) $line->amount, 2), 2, '');
            $minor += ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
