<?php

namespace Tests\Unit;

use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class InvoicePaymentAllocationCalculatorTest extends TestCase
{
    private InvoicePaymentAllocationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoicePaymentAllocationCalculator();
    }

    public function test_one_line_without_payments_remains_unpaid(): void
    {
        $result = $this->calculator->calculate([$this->line(1, '100.00')], []);

        $this->assertSame([
            'total' => '100.00',
            'allocated' => '0.00',
            'remaining' => '100.00',
        ], $result['lines'][1]);
    }

    public function test_partial_confirmed_payment_reduces_line_remaining_amount(): void
    {
        $result = $this->calculator->calculate(
            [$this->line(1, '100.00')],
            [$this->payment(1, '40.00')]
        );

        $this->assertSame('40.00', $result['lines'][1]['allocated']);
        $this->assertSame('60.00', $result['lines'][1]['remaining']);
    }

    public function test_confirmed_payment_can_fully_close_a_line(): void
    {
        $result = $this->calculator->calculate(
            [$this->line(1, '100.00')],
            [$this->payment(1, '100.00')]
        );

        $this->assertSame('0.00', $result['lines'][1]['remaining']);
        $this->assertSame('100.00', $result['payments'][1]['allocated']);
    }

    public function test_older_period_is_allocated_first(): void
    {
        $newer = $this->line(1, '100.00', '2026-06-01');
        $older = $this->line(2, '100.00', '2026-05-01');

        $result = $this->calculator->calculate(
            [$newer, $older],
            [$this->payment(1, '100.00')]
        );

        $this->assertSame(2, $result['allocations'][0]['invoice_line_id']);
        $this->assertSame('0.00', $result['lines'][2]['remaining']);
        $this->assertSame('100.00', $result['lines'][1]['remaining']);
    }

    public function test_payment_can_continue_partially_into_the_next_period(): void
    {
        $result = $this->calculator->calculate([
            $this->line(1, '100.00', '2026-05-01'),
            $this->line(2, '100.00', '2026-06-01'),
        ], [$this->payment(1, '130.00')]);

        $this->assertSame([
            ['payment_id' => 1, 'invoice_line_id' => 1, 'amount' => '100.00'],
            ['payment_id' => 1, 'invoice_line_id' => 2, 'amount' => '30.00'],
        ], $result['allocations']);
        $this->assertSame('70.00', $result['lines'][2]['remaining']);
    }

    public function test_second_payment_continues_from_first_unpaid_line(): void
    {
        $result = $this->calculator->calculate([
            $this->line(1, '100.00', '2026-05-01'),
            $this->line(2, '100.00', '2026-06-01'),
            $this->line(3, '50.00'),
        ], [
            $this->payment(1, '130.00', '2026-07-01'),
            $this->payment(2, '80.00', '2026-07-02'),
        ]);

        $this->assertSame([
            ['payment_id' => 1, 'invoice_line_id' => 1, 'amount' => '100.00'],
            ['payment_id' => 1, 'invoice_line_id' => 2, 'amount' => '30.00'],
            ['payment_id' => 2, 'invoice_line_id' => 2, 'amount' => '70.00'],
            ['payment_id' => 2, 'invoice_line_id' => 3, 'amount' => '10.00'],
        ], $result['allocations']);
        $this->assertSame('40.00', $result['lines'][3]['remaining']);
    }

    public function test_equal_period_start_is_ordered_by_line_id(): void
    {
        $result = $this->calculator->calculate([
            $this->line(20, '10.00', '2026-06-01'),
            $this->line(12, '10.00', '2026-06-01'),
        ], [$this->payment(1, '10.00')]);

        $this->assertSame(12, $result['allocations'][0]['invoice_line_id']);
    }

    public function test_line_without_period_is_allocated_after_periodic_lines(): void
    {
        $result = $this->calculator->calculate([
            $this->line(1, '10.00'),
            $this->line(2, '10.00', '2026-06-01'),
        ], [$this->payment(1, '10.00')]);

        $this->assertSame(2, $result['allocations'][0]['invoice_line_id']);
    }

    public function test_lines_without_period_are_ordered_by_id(): void
    {
        $result = $this->calculator->calculate([
            $this->line(9, '10.00'),
            $this->line(4, '10.00'),
        ], [$this->payment(1, '20.00')]);

        $this->assertSame([4, 9], array_column($result['allocations'], 'invoice_line_id'));
    }

    public function test_pending_and_cancelled_payments_are_ignored_and_omitted(): void
    {
        $result = $this->calculator->calculate([$this->line(1, '100.00')], [
            $this->payment(1, '40.00', status: 'pending'),
            $this->payment(2, '50.00', status: 'cancelled'),
        ]);

        $this->assertSame([], $result['allocations']);
        $this->assertSame([], $result['payments']);
        $this->assertSame('0.00', $result['totals']['confirmed_payment_total']);
    }

    public function test_confirmed_credit_balance_comment_does_not_change_allocation(): void
    {
        $payment = $this->payment(1, '30.00');
        $payment->comment = 'Автоматически применён Credit Balance';

        $result = $this->calculator->calculate([$this->line(1, '100.00')], [$payment]);

        $this->assertSame('30.00', $result['payments'][1]['allocated']);
        $this->assertSame('70.00', $result['lines'][1]['remaining']);
    }

    public function test_overpayment_remains_unallocated_and_does_not_overpay_line(): void
    {
        $result = $this->calculator->calculate(
            [$this->line(1, '100.00')],
            [$this->payment(1, '125.00')]
        );

        $this->assertSame('100.00', $result['lines'][1]['allocated']);
        $this->assertSame('25.00', $result['payments'][1]['unallocated']);
        $this->assertSame('25.00', $result['totals']['overpayment_total']);
        $this->assertCount(1, $result['allocations']);
    }

    public function test_payments_on_same_date_are_ordered_by_id(): void
    {
        $result = $this->calculator->calculate([$this->line(1, '20.00')], [
            $this->payment(8, '10.00', '2026-07-01'),
            $this->payment(3, '10.00', '2026-07-01'),
        ]);

        $this->assertSame([3, 8], array_column($result['allocations'], 'payment_id'));
    }

    public function test_payments_are_ordered_by_business_payment_date(): void
    {
        $result = $this->calculator->calculate([$this->line(1, '20.00')], [
            $this->payment(1, '10.00', '2026-07-02'),
            $this->payment(2, '10.00', '2026-07-01'),
        ]);

        $this->assertSame([2, 1], array_column($result['allocations'], 'payment_id'));
    }

    public function test_minor_unit_arithmetic_has_no_float_drift(): void
    {
        $result = $this->calculator->calculate([
            $this->line(1, 0.01, '2026-05-01'),
            $this->line(2, 0.02, '2026-06-01'),
        ], [$this->payment(1, 0.03)]);

        $this->assertSame('0.03', $result['totals']['line_total']);
        $this->assertSame('0.03', $result['totals']['applied_total']);
        $this->assertSame('0.00', $result['totals']['remaining_total']);
    }

    public function test_values_with_more_than_two_decimals_are_rounded_half_up(): void
    {
        $result = $this->calculator->calculate(
            [$this->line(1, '10.255')],
            [$this->payment(1, '10.254')]
        );

        $this->assertSame('10.26', $result['lines'][1]['total']);
        $this->assertSame('10.25', $result['payments'][1]['amount']);
        $this->assertSame('0.01', $result['lines'][1]['remaining']);
    }

    public function test_negative_line_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([$this->line(1, '-0.01')], []);
    }

    public function test_negative_payment_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([], [$this->payment(1, '-0.01')]);
    }

    public function test_lines_from_different_invoices_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([
            $this->line(1, '10.00', invoiceId: 1),
            $this->line(2, '10.00', invoiceId: 2),
        ], []);
    }

    public function test_payment_from_another_invoice_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate(
            [$this->line(1, '10.00', invoiceId: 1)],
            [$this->payment(1, '10.00', invoiceId: 2)]
        );
    }

    public function test_empty_inputs_return_zero_totals(): void
    {
        $result = $this->calculator->calculate([], []);

        $this->assertSame([], $result['allocations']);
        $this->assertSame([], $result['lines']);
        $this->assertSame([], $result['payments']);
        $this->assertSame([
            'line_total' => '0.00',
            'confirmed_payment_total' => '0.00',
            'applied_total' => '0.00',
            'remaining_total' => '0.00',
            'overpayment_total' => '0.00',
        ], $result['totals']);
    }

    public function test_calculation_does_not_mutate_models(): void
    {
        $line = $this->line(1, '100.00', '2026-05-01');
        $payment = $this->payment(1, '40.00');
        $lineAttributes = $line->getAttributes();
        $paymentAttributes = $payment->getAttributes();

        $this->calculator->calculate([$line], [$payment]);

        $this->assertSame($lineAttributes, $line->getAttributes());
        $this->assertSame($paymentAttributes, $payment->getAttributes());
    }

    public function test_two_identical_calls_are_deterministic(): void
    {
        $lines = [
            $this->line(2, '50.00'),
            $this->line(1, '100.00', '2026-05-01'),
        ];
        $payments = [
            $this->payment(2, '60.00', '2026-07-02'),
            $this->payment(1, '40.00', '2026-07-01'),
        ];

        $this->assertSame(
            $this->calculator->calculate($lines, $payments),
            $this->calculator->calculate($lines, $payments)
        );
    }

    public function test_totals_match_invoice_level_formulas(): void
    {
        $result = $this->calculator->calculate([
            $this->line(1, '100.00'),
            $this->line(2, '50.00'),
        ], [
            $this->payment(1, '125.00'),
            $this->payment(2, '50.00'),
            $this->payment(3, '999.00', status: 'pending'),
        ]);

        $this->assertSame('150.00', $result['totals']['line_total']);
        $this->assertSame('175.00', $result['totals']['confirmed_payment_total']);
        $this->assertSame('150.00', $result['totals']['applied_total']);
        $this->assertSame('0.00', $result['totals']['remaining_total']);
        $this->assertSame('25.00', $result['totals']['overpayment_total']);
    }

    private function line(
        int $id,
        string|int|float $amount,
        ?string $periodStart = null,
        int $invoiceId = 1
    ): InvoiceLine {
        $line = new InvoiceLine([
            'invoice_id' => $invoiceId,
            'description' => "Line {$id}",
            'amount' => $amount,
            'period_start' => $periodStart,
        ]);
        $line->id = $id;

        return $line;
    }

    private function payment(
        int $id,
        string|int|float $amount,
        string $paymentDate = '2026-07-01',
        string $status = 'confirmed',
        int $invoiceId = 1
    ): Payment {
        $payment = new Payment([
            'invoice_id' => $invoiceId,
            'company_id' => 1,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]);
        $payment->id = $id;

        return $payment;
    }
}
