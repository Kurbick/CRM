<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\OneTimeServiceDebtCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneTimeServiceDebtCalculatorTest extends TestCase
{
    private OneTimeServiceDebtCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new OneTimeServiceDebtCalculator();
    }

    public function test_empty_list_returns_zero_totals(): void
    {
        $result = $this->calculate([]);
        $this->assertSame([], $result['lines']);
        $this->assertSame(0, $result['totals']['line_count']);
        $this->assertSame('0.00', $result['totals']['remaining']);
    }

    #[DataProvider('excludedStatuses')]
    public function test_draft_and_cancelled_invoices_are_excluded(string $status): void
    {
        $this->assertSame(0, $this->calculate([$this->line(invoiceStatus: $status)])['totals']['line_count']);
    }

    public static function excludedStatuses(): array { return [['draft'], ['cancelled']]; }

    #[DataProvider('allocationCases')]
    public function test_payment_status_controls_allocated_amount(string $status, string $amount, string $allocated, string $remaining, string $paymentStatus): void
    {
        $line = $this->line();
        $this->setAllocations($line, [$this->allocation($line, $amount, $status)]);
        $result = $this->calculate([$line])['lines'][0];
        $this->assertSame([$allocated, $remaining, $paymentStatus], [$result['allocated'], $result['remaining'], $result['payment_status']]);
    }

    public static function allocationCases(): array
    {
        return [
            'unpaid' => ['pending', '40.00', '0.00', '100.00', 'unpaid'],
            'cancelled' => ['cancelled', '40.00', '0.00', '100.00', 'unpaid'],
            'partial' => ['confirmed', '40.00', '40.00', '60.00', 'partially_paid'],
            'paid' => ['confirmed', '100.00', '100.00', '0.00', 'paid'],
        ];
    }

    public function test_confirmed_credit_balance_comment_does_not_change_calculation(): void
    {
        $line = $this->line();
        $this->setAllocations($line, [$this->allocation($line, '25.00', comment: 'Credit Balance')]);
        $this->assertSame('25.00', $this->calculate([$line])['lines'][0]['allocated']);
    }

    public function test_multiple_allocations_sum_in_minor_units(): void
    {
        $line = $this->line(amount: '0.03');
        $this->setAllocations($line, [$this->allocation($line, '0.01', id: 1), $this->allocation($line, '0.02', id: 2)]);
        $this->assertSame('0.03', $this->calculate([$line])['lines'][0]['allocated']);
    }

    public function test_allocation_above_line_amount_is_rejected(): void
    {
        $line = $this->line(amount: '10.00');
        $this->setAllocations($line, [$this->allocation($line, '10.01')]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exceed InvoiceLine');
        $this->calculate([$line]);
    }

    public function test_cross_invoice_allocation_is_rejected(): void
    {
        $line = $this->line();
        $allocation = $this->allocation($line, '10.00');
        $allocation->getRelation('payment')->invoice_id = 999;
        $this->setAllocations($line, [$allocation]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('belongs to invoice 999');
        $this->calculate([$line]);
    }

    public function test_subscription_line_is_excluded_and_dual_link_is_rejected(): void
    {
        $subscription = $this->line(orderId: null);
        $subscription->subscription_id = 5;
        $this->assertSame([], $this->calculate([$subscription])['lines']);

        $dual = $this->line();
        $dual->subscription_id = 5;
        $this->expectException(LogicException::class);
        $this->calculate([$dual]);
    }

    public function test_order_and_manual_lines_use_expected_titles(): void
    {
        $order = $this->line(description: 'Description');
        $manual = $this->line(id: 11, orderId: null, description: 'Manual service');
        $fallback = $this->line(id: 12, orderId: null, description: '');
        $titles = array_column($this->calculate([$order, $manual, $fallback])['lines'], 'service_title');
        $this->assertSame(['Website development', 'Manual service', 'Разовая услуга'], $titles);
    }

    #[DataProvider('dueDates')]
    public function test_overdue_uses_calendar_date(string $dueDate, bool $expected, int $days): void
    {
        $line = $this->calculate([$this->line(dueDate: $dueDate)])['lines'][0];
        $this->assertSame($expected, $line['is_overdue']);
        $this->assertSame($days, $line['days_overdue']);
    }

    public static function dueDates(): array
    {
        return [['2026-05-14', true, 1], ['2026-05-15', false, 0], ['2026-05-16', false, 0]];
    }

    public function test_zero_line_is_paid_and_creates_no_debt(): void
    {
        $result = $this->calculate([$this->line(amount: '0.00')]);
        $this->assertSame('paid', $result['lines'][0]['payment_status']);
        $this->assertSame('0.00', $result['totals']['remaining']);
    }

    #[DataProvider('negativeTargets')]
    public function test_negative_amounts_are_rejected(string $target): void
    {
        $line = $this->line(amount: $target === 'line' ? '-0.01' : '100.00');
        if ($target === 'allocation') $this->setAllocations($line, [$this->allocation($line, '-0.01')]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be negative');
        $this->calculate([$line]);
    }

    public static function negativeTargets(): array { return [['line'], ['allocation']]; }

    #[DataProvider('missingRelations')]
    public function test_missing_required_relations_fail_without_lazy_loading(string $relation): void
    {
        $line = $this->line();
        $line->unsetRelation($relation);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("missing loaded relation {$relation}");
        $this->calculate([$line]);
    }

    public static function missingRelations(): array { return [['invoice'], ['order'], ['allocations']]; }

    public function test_missing_payment_relation_fails_without_lazy_loading(): void
    {
        $line = $this->line();
        $allocation = $this->allocation($line, '10.00');
        $allocation->unsetRelation('payment');
        $this->setAllocations($line, [$allocation]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing loaded relation payment');
        $this->calculate([$line]);
    }

    public function test_sorting_is_stable_and_repeated_calls_do_not_modify_models(): void
    {
        $lines = [
            $this->line(id: 3, dueDate: null, issueDate: '2026-05-01'),
            $this->line(id: 2, dueDate: '2026-06-01', issueDate: '2026-05-02'),
            $this->line(id: 1, dueDate: '2026-05-20', issueDate: '2026-05-03'),
        ];
        $before = array_map(fn(InvoiceLine $line) => $line->getAttributes(), $lines);
        $first = $this->calculate($lines);
        $this->assertSame([1, 2, 3], array_column($first['lines'], 'invoice_line_id'));
        $this->assertSame($first, $this->calculate($lines));
        $this->assertSame($before, array_map(fn(InvoiceLine $line) => $line->getAttributes(), $lines));
    }

    private function calculate(array $lines): array
    {
        return $this->calculator->calculate($lines, CarbonImmutable::parse('2026-05-15 18:30:00'));
    }

    private function line(int $id = 10, ?int $orderId = 8, string $description = 'Description', string $amount = '100.00', string $invoiceStatus = 'issued', ?string $dueDate = '2026-05-20', ?string $issueDate = '2026-05-01'): InvoiceLine
    {
        $invoice = new Invoice(['company_id' => 1, 'contract_id' => 2, 'invoice_number' => 'INV-0001', 'issue_date' => $issueDate, 'due_date' => $dueDate, 'status' => $invoiceStatus]);
        $invoice->id = 32;
        $order = null;
        if ($orderId !== null) { $order = new Order(['title' => 'Website development']); $order->id = $orderId; }
        $line = new InvoiceLine(['invoice_id' => 32, 'order_id' => $orderId, 'description' => $description, 'amount' => $amount]);
        $line->id = $id;
        $line->setRelation('invoice', $invoice);
        $line->setRelation('order', $order);
        $line->setRelation('allocations', new Collection());
        return $line;
    }

    private function allocation(InvoiceLine $line, string $amount, string $status = 'confirmed', ?string $comment = null, int $id = 1): PaymentAllocation
    {
        $payment = new Payment(['invoice_id' => $line->invoice_id, 'company_id' => 1, 'amount' => $amount, 'status' => $status, 'comment' => $comment]);
        $payment->id = 100 + $id;
        $allocation = new PaymentAllocation(['payment_id' => $payment->id, 'invoice_line_id' => $line->id, 'amount' => $amount]);
        $allocation->id = $id;
        $allocation->setRelation('payment', $payment);
        return $allocation;
    }

    private function setAllocations(InvoiceLine $line, array $allocations): void
    {
        $line->setRelation('allocations', new Collection($allocations));
    }
}
