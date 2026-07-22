<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Subscription;
use App\Services\SubscriptionPeriodDebtCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionPeriodDebtCalculatorTest extends TestCase
{
    private SubscriptionPeriodDebtCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SubscriptionPeriodDebtCalculator();
    }

    public function test_empty_list_returns_zero_totals(): void
    {
        $result = $this->calculate([]);

        $this->assertSame([], $result['subscriptions']);
        $this->assertSame([], $result['anomalies']);
        $this->assertSame(0, $result['totals']['subscription_count']);
        $this->assertSame(0, $result['totals']['period_count']);
        $this->assertSame('0.00', $result['totals']['remaining']);
    }

    #[DataProvider('excludedInvoiceStatuses')]
    public function test_draft_and_cancelled_invoices_are_excluded(string $status): void
    {
        $result = $this->calculate([
            $this->line(periodStart: null, periodEnd: null, invoiceStatus: $status),
        ]);

        $this->assertSame(0, $result['totals']['period_count']);
        $this->assertSame([], $result['anomalies']);
    }

    public static function excludedInvoiceStatuses(): array
    {
        return [['draft'], ['cancelled']];
    }

    public function test_issued_line_without_allocations_is_unpaid(): void
    {
        $period = $this->period($this->calculate([$this->line(amount: '100.00')]));

        $this->assertSame('unpaid', $period['payment_status']);
        $this->assertSame('100.00', $period['remaining']);
        $this->assertSame('0.00', $period['allocated']);
    }

    #[DataProvider('allocationCases')]
    public function test_allocation_statuses(
        string $paymentStatus,
        string $allocationAmount,
        string $expectedAllocated,
        string $expectedRemaining,
        string $expectedStatus
    ): void {
        $line = $this->line(amount: '100.00');
        $this->setAllocations($line, [
            $this->allocation($line, $allocationAmount, $paymentStatus),
        ]);
        $period = $this->period($this->calculate([$line]));

        $this->assertSame($expectedAllocated, $period['allocated']);
        $this->assertSame($expectedRemaining, $period['remaining']);
        $this->assertSame($expectedStatus, $period['payment_status']);
    }

    public static function allocationCases(): array
    {
        return [
            'partial confirmed' => ['confirmed', '40.00', '40.00', '60.00', 'partially_paid'],
            'full confirmed' => ['confirmed', '100.00', '100.00', '0.00', 'paid'],
            'pending ignored' => ['pending', '40.00', '0.00', '100.00', 'unpaid'],
            'cancelled ignored' => ['cancelled', '40.00', '0.00', '100.00', 'unpaid'],
        ];
    }

    public function test_confirmed_credit_balance_payment_is_counted_without_reading_comment(): void
    {
        $line = $this->line();
        $this->setAllocations($line, [
            $this->allocation($line, '30.00', 'confirmed', 'Оплата из кредитного баланса'),
        ]);

        $this->assertSame('30.00', $this->period($this->calculate([$line]))['allocated']);
    }

    public function test_multiple_allocations_are_summed_exactly(): void
    {
        $line = $this->line(amount: '0.03');
        $this->setAllocations($line, [
            $this->allocation($line, '0.01', id: 1),
            $this->allocation($line, '0.02', id: 2),
        ]);
        $period = $this->period($this->calculate([$line]));

        $this->assertSame('0.03', $period['allocated']);
        $this->assertSame('0.00', $period['remaining']);
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

    #[DataProvider('nonSubscriptionLines')]
    public function test_one_time_and_manual_lines_are_excluded(?int $orderId): void
    {
        $line = $this->line(subscriptionId: null);
        $line->order_id = $orderId;

        $result = $this->calculate([$line]);

        $this->assertSame(0, $result['totals']['period_count']);
        $this->assertSame([], $result['anomalies']);
    }

    public static function nonSubscriptionLines(): array
    {
        return ['one-time' => [7], 'manual' => [null]];
    }

    #[DataProvider('missingPeriods')]
    public function test_missing_period_metadata_creates_anomaly(
        ?string $start,
        ?string $end,
        string $type
    ): void {
        $result = $this->calculate([$this->line(periodStart: $start, periodEnd: $end)]);

        $this->assertSame(0, $result['totals']['period_count']);
        $this->assertSame($type, $result['anomalies'][0]['type']);
        $this->assertSame(10, $result['anomalies'][0]['invoice_line_id']);
    }

    public static function missingPeriods(): array
    {
        return [
            'start' => [null, '2026-05-31', 'missing_period_start'],
            'end' => ['2026-05-01', null, 'missing_period_end'],
        ];
    }

    public function test_missing_both_period_dates_creates_two_anomalies(): void
    {
        $result = $this->calculate([$this->line(periodStart: null, periodEnd: null)]);

        $this->assertSame(
            ['missing_period_start', 'missing_period_end'],
            array_column($result['anomalies'], 'type')
        );
    }

    public function test_past_current_and_future_period_statuses(): void
    {
        $lines = [
            $this->line(id: 1, periodStart: '2026-04-01', periodEnd: '2026-04-30'),
            $this->line(id: 2, periodStart: '2026-05-01', periodEnd: '2026-05-31'),
            $this->line(id: 3, periodStart: '2026-06-01', periodEnd: '2026-06-30'),
        ];
        $periods = $this->calculate($lines, '2026-05-15')['subscriptions'][0]['periods'];

        $this->assertSame(['past', 'current', 'future'], array_column($periods, 'period_status'));
        $this->assertSame(1, $this->calculate($lines, '2026-05-15')['totals']['past_period_count']);
    }

    #[DataProvider('dueDates')]
    public function test_overdue_boundary(string $dueDate, bool $overdue, int $days): void
    {
        $period = $this->period($this->calculate([
            $this->line(dueDate: $dueDate),
        ], '2026-05-15'));

        $this->assertSame($overdue, $period['is_overdue']);
        $this->assertSame($days, $period['days_overdue']);
    }

    public static function dueDates(): array
    {
        return [
            'yesterday' => ['2026-05-14', true, 1],
            'today' => ['2026-05-15', false, 0],
            'tomorrow' => ['2026-05-16', false, 0],
        ];
    }

    public function test_partial_overdue_period_counts_only_remaining_as_overdue(): void
    {
        $line = $this->line(amount: '100.00', dueDate: '2026-05-01');
        $this->setAllocations($line, [$this->allocation($line, '70.00')]);
        $result = $this->calculate([$line], '2026-05-21');

        $this->assertSame('30.00', $result['totals']['overdue_remaining']);
        $this->assertSame(1, $result['totals']['overdue_period_count']);
        $this->assertSame(1, $result['totals']['unpaid_period_count']);
        $this->assertSame(1, $result['totals']['partially_paid_period_count']);
    }

    public function test_issued_before_due_date_is_debt_but_not_overdue(): void
    {
        $result = $this->calculate([$this->line(dueDate: '2026-05-20')], '2026-05-15');

        $this->assertSame('100.00', $result['totals']['remaining']);
        $this->assertSame('0.00', $result['totals']['overdue_remaining']);
    }

    public function test_zero_amount_line_is_paid_and_not_debt(): void
    {
        $result = $this->calculate([$this->line(amount: '0.00')]);

        $this->assertSame('paid', $this->period($result)['payment_status']);
        $this->assertSame(1, $result['totals']['paid_period_count']);
        $this->assertSame(0, $result['totals']['unpaid_period_count']);
    }

    public function test_grouping_and_totals_for_multiple_subscriptions(): void
    {
        $result = $this->calculate([
            $this->line(id: 1, subscriptionId: 5, subscriptionTitle: 'Support', amount: '10.00'),
            $this->line(id: 2, subscriptionId: 5, subscriptionTitle: 'Support', amount: '20.00'),
            $this->line(id: 3, subscriptionId: 6, subscriptionTitle: 'Hosting', amount: '30.00'),
        ]);

        $this->assertSame(2, $result['totals']['subscription_count']);
        $this->assertSame(3, $result['totals']['period_count']);
        $this->assertSame('60.00', $result['totals']['total']);
        $support = array_values(array_filter(
            $result['subscriptions'],
            fn(array $group): bool => $group['subscription_id'] === 5
        ))[0];
        $this->assertSame(2, $support['totals']['period_count']);
        $this->assertSame('30.00', $support['totals']['remaining']);
    }

    public function test_subscriptions_and_periods_have_stable_sorting(): void
    {
        $result = $this->calculate([
            $this->line(id: 30, subscriptionId: 3, subscriptionTitle: 'Beta', periodStart: '2026-05-01'),
            $this->line(
                id: 20,
                subscriptionId: 2,
                subscriptionTitle: 'Alpha',
                periodStart: '2026-06-01',
                periodEnd: '2026-06-30'
            ),
            $this->line(id: 10, subscriptionId: 2, subscriptionTitle: 'Alpha', periodStart: '2026-05-01'),
            $this->line(id: 40, subscriptionId: 1, subscriptionTitle: 'Alpha', periodStart: '2026-04-01'),
        ]);

        $this->assertSame([1, 2, 3], array_column($result['subscriptions'], 'subscription_id'));
        $alphaTwo = $result['subscriptions'][1];
        $this->assertSame([10, 20], array_column($alphaTwo['periods'], 'invoice_line_id'));
    }

    #[DataProvider('negativeAmounts')]
    public function test_negative_line_or_allocation_amount_is_rejected(string $target): void
    {
        $line = $this->line(amount: $target === 'line' ? '-0.01' : '100.00');
        if ($target === 'allocation') {
            $this->setAllocations($line, [$this->allocation($line, '-0.01')]);
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be negative');
        $this->calculate([$line]);
    }

    public static function negativeAmounts(): array
    {
        return [['line'], ['allocation']];
    }

    #[DataProvider('missingRelations')]
    public function test_missing_required_line_relation_fails_without_lazy_loading(string $relation): void
    {
        $line = $this->line();
        $line->unsetRelation($relation);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("missing loaded relation {$relation}");
        $this->calculate([$line]);
    }

    public static function missingRelations(): array
    {
        return [['invoice'], ['subscription'], ['allocations']];
    }

    public function test_missing_allocation_payment_relation_fails_without_lazy_loading(): void
    {
        $line = $this->line();
        $allocation = $this->allocation($line, '10.00');
        $allocation->unsetRelation('payment');
        $this->setAllocations($line, [$allocation]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing loaded relation payment');
        $this->calculate([$line]);
    }

    public function test_models_are_not_modified_and_repeated_results_are_identical(): void
    {
        $line = $this->line();
        $allocation = $this->allocation($line, '10.00');
        $this->setAllocations($line, [$allocation]);
        $models = [$line, $line->getRelation('invoice'), $line->getRelation('subscription'), $allocation, $allocation->getRelation('payment')];
        $before = array_map(fn($model): array => $model->getAttributes(), $models);

        $first = $this->calculate([$line]);
        $second = $this->calculate([$line]);

        $this->assertSame($first, $second);
        $this->assertSame($before, array_map(fn($model): array => $model->getAttributes(), $models));
    }

    private function calculate(array $lines, string $asOf = '2026-05-15'): array
    {
        return $this->calculator->calculate($lines, CarbonImmutable::parse($asOf));
    }

    private function period(array $result): array
    {
        return $result['subscriptions'][0]['periods'][0];
    }

    private function line(
        int $id = 10,
        ?int $subscriptionId = 5,
        string $subscriptionTitle = 'Техническая поддержка',
        string $amount = '100.00',
        ?string $periodStart = '2026-05-01',
        ?string $periodEnd = '2026-05-31',
        string $invoiceStatus = 'issued',
        ?string $dueDate = '2026-05-11'
    ): InvoiceLine {
        $invoice = new Invoice([
            'company_id' => 1,
            'contract_id' => 2,
            'invoice_number' => 'INV-0001',
            'issue_date' => '2026-05-01',
            'due_date' => $dueDate,
            'status' => $invoiceStatus,
            'total_amount' => $amount,
        ]);
        $invoice->id = 32;

        $subscription = null;
        if ($subscriptionId !== null) {
            $subscription = new Subscription([
                'title' => $subscriptionTitle,
                'billing_period' => 'monthly',
                'status' => 'active',
            ]);
            $subscription->id = $subscriptionId;
        }

        $line = new InvoiceLine([
            'invoice_id' => 32,
            'subscription_id' => $subscriptionId,
            'description' => 'Period',
            'amount' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
        $line->id = $id;
        $line->setRelation('invoice', $invoice);
        $line->setRelation('subscription', $subscription);
        $line->setRelation('allocations', new Collection());

        return $line;
    }

    private function allocation(
        InvoiceLine $line,
        string $amount,
        string $status = 'confirmed',
        ?string $comment = null,
        int $id = 1
    ): PaymentAllocation {
        $payment = new Payment([
            'invoice_id' => $line->invoice_id,
            'company_id' => 1,
            'payment_date' => '2026-05-10',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]);
        $payment->id = 100 + $id;

        $allocation = new PaymentAllocation([
            'payment_id' => $payment->id,
            'invoice_line_id' => $line->id,
            'amount' => $amount,
        ]);
        $allocation->id = $id;
        $allocation->setRelation('payment', $payment);

        return $allocation;
    }

    /** @param list<PaymentAllocation> $allocations */
    private function setAllocations(InvoiceLine $line, array $allocations): void
    {
        $line->setRelation('allocations', new Collection($allocations));
    }
}
