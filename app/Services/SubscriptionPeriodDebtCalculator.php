<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;

class SubscriptionPeriodDebtCalculator
{
    private const INCLUDED_INVOICE_STATUSES = ['issued', 'partially_paid', 'paid'];

    /**
     * Calculate subscription-period debt from already loaded models.
     *
     * The subscriptions key is a stable, sequential list sorted by title and
     * id. Each subscription contains a sorted periods list and aggregate
     * totals. No relation is loaded by this service.
     *
     * @param  iterable<InvoiceLine>  $invoiceLines
     * @return array{
     *     subscriptions: list<array{
     *         subscription_id: int,
     *         subscription_title: string,
     *         periods: list<array<string, mixed>>,
     *         totals: array<string, int|string>
     *     }>,
     *     totals: array<string, int|string>,
     *     anomalies: list<array{invoice_line_id: int, type: string, message: string}>
     * }
     */
    public function calculate(iterable $invoiceLines, CarbonInterface $asOf): array
    {
        $asOfDate = CarbonImmutable::instance($asOf)->startOfDay();
        $groups = [];
        $anomalies = [];

        foreach ($invoiceLines as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new InvalidArgumentException('Every invoice line must be an InvoiceLine instance.');
            }

            $lineId = $this->positiveId($line->id, 'InvoiceLine id');
            $invoice = $this->requiredRelation($line, 'invoice', Invoice::class, $lineId);
            $subscription = $this->requiredNullableRelation(
                $line,
                'subscription',
                Subscription::class,
                $lineId
            );
            $allocations = $this->requiredIterableRelation($line, 'allocations', $lineId);

            if ($line->subscription_id === null) {
                continue;
            }

            if (!$subscription instanceof Subscription) {
                throw new LogicException(
                    "InvoiceLine {$lineId} relation subscription is null for subscription_id {$line->subscription_id}."
                );
            }

            if (!in_array($invoice->status, self::INCLUDED_INVOICE_STATUSES, true)) {
                continue;
            }

            $missingPeriod = false;
            if ($line->period_start === null || $line->period_start === '') {
                $anomalies[] = $this->anomaly($lineId, 'missing_period_start');
                $missingPeriod = true;
            }

            if ($line->period_end === null || $line->period_end === '') {
                $anomalies[] = $this->anomaly($lineId, 'missing_period_end');
                $missingPeriod = true;
            }

            if ($missingPeriod) {
                continue;
            }

            $invoiceId = $this->positiveId($line->invoice_id, "InvoiceLine {$lineId} invoice_id");

            if ((int) $invoice->id !== $invoiceId) {
                throw new LogicException(
                    "InvoiceLine {$lineId} belongs to invoice {$invoiceId}, but its loaded invoice relation is {$invoice->id}."
                );
            }

            $total = $this->toMinorUnits($line->amount, "InvoiceLine {$lineId} amount");
            $allocated = $this->confirmedAllocatedMinor($line, $allocations, $invoiceId, $lineId);

            if ($allocated > $total) {
                throw new LogicException(
                    "Confirmed allocations ({$this->formatMinorUnits($allocated)}) exceed InvoiceLine {$lineId} amount ({$this->formatMinorUnits($total)})."
                );
            }

            $remaining = $total - $allocated;
            $periodStart = $this->date($line->period_start, "InvoiceLine {$lineId} period_start");
            $periodEnd = $this->date($line->period_end, "InvoiceLine {$lineId} period_end");

            if ($periodEnd->lt($periodStart)) {
                throw new LogicException("InvoiceLine {$lineId} period_end is before period_start.");
            }

            $dueDate = $invoice->due_date === null || $invoice->due_date === ''
                ? null
                : $this->date($invoice->due_date, "Invoice {$invoiceId} due_date");
            $isOverdue = $remaining > 0 && $dueDate !== null && $dueDate->lt($asOfDate);
            $subscriptionId = $this->positiveId(
                $line->subscription_id,
                "InvoiceLine {$lineId} subscription_id"
            );

            if ((int) $subscription->id !== $subscriptionId) {
                throw new LogicException(
                    "InvoiceLine {$lineId} references subscription {$subscriptionId}, but its loaded subscription relation is {$subscription->id}."
                );
            }

            $period = [
                'invoice_line_id' => $lineId,
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_status' => (string) $invoice->status,
                'company_id' => $this->positiveId($invoice->company_id, "Invoice {$invoiceId} company_id"),
                'contract_id' => $invoice->contract_id === null
                    ? null
                    : $this->positiveId($invoice->contract_id, "Invoice {$invoiceId} contract_id"),
                'subscription_id' => $subscriptionId,
                'subscription_title' => (string) $subscription->title,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'period_status' => $this->periodStatus($periodStart, $periodEnd, $asOfDate),
                'issue_date' => $invoice->issue_date === null
                    ? null
                    : $this->date($invoice->issue_date, "Invoice {$invoiceId} issue_date")->toDateString(),
                'due_date' => $dueDate?->toDateString(),
                'total' => $this->formatMinorUnits($total),
                'allocated' => $this->formatMinorUnits($allocated),
                'remaining' => $this->formatMinorUnits($remaining),
                'payment_status' => $this->paymentStatus($allocated, $remaining),
                'is_overdue' => $isOverdue,
                'days_overdue' => $isOverdue ? (int) $dueDate->diffInDays($asOfDate) : 0,
            ];

            $groups[$subscriptionId] ??= [
                'subscription_id' => $subscriptionId,
                'subscription_title' => (string) $subscription->title,
                'periods' => [],
            ];
            $groups[$subscriptionId]['periods'][] = $period;
        }

        $subscriptions = array_values($groups);

        foreach ($subscriptions as &$subscription) {
            usort($subscription['periods'], fn(array $left, array $right): int => [
                $left['period_start'], $left['period_end'], $left['invoice_line_id'],
            ] <=> [
                $right['period_start'], $right['period_end'], $right['invoice_line_id'],
            ]);
            $subscription['totals'] = $this->totals($subscription['periods']);
        }
        unset($subscription);

        usort($subscriptions, fn(array $left, array $right): int => [
            $left['subscription_title'], $left['subscription_id'],
        ] <=> [
            $right['subscription_title'], $right['subscription_id'],
        ]);

        $allPeriods = [];
        foreach ($subscriptions as $subscription) {
            array_push($allPeriods, ...$subscription['periods']);
        }

        $totals = $this->totals($allPeriods);
        $totals = ['subscription_count' => count($subscriptions), ...$totals];

        return [
            'subscriptions' => $subscriptions,
            'totals' => $totals,
            'anomalies' => $anomalies,
        ];
    }

    private function confirmedAllocatedMinor(
        InvoiceLine $line,
        iterable $allocations,
        int $invoiceId,
        int $lineId
    ): int {
        $allocated = 0;

        foreach ($allocations as $allocation) {
            if (!$allocation instanceof PaymentAllocation) {
                throw new LogicException("InvoiceLine {$lineId} allocations must contain PaymentAllocation models.");
            }

            if (!$allocation->relationLoaded('payment')) {
                throw new LogicException(
                    "PaymentAllocation {$allocation->id} for InvoiceLine {$lineId} is missing loaded relation payment."
                );
            }

            $payment = $allocation->getRelation('payment');
            if (!$payment instanceof Payment) {
                throw new LogicException("PaymentAllocation {$allocation->id} has an invalid payment relation.");
            }

            if ((int) $allocation->invoice_line_id !== $lineId) {
                throw new LogicException(
                    "PaymentAllocation {$allocation->id} references InvoiceLine {$allocation->invoice_line_id}, expected {$lineId}."
                );
            }

            if ((int) $payment->invoice_id !== $invoiceId) {
                throw new LogicException(
                    "PaymentAllocation {$allocation->id} payment {$payment->id} belongs to invoice {$payment->invoice_id}, expected {$invoiceId}."
                );
            }

            $amount = $this->toMinorUnits(
                $allocation->amount,
                "PaymentAllocation {$allocation->id} amount"
            );

            if ($amount <= 0) {
                throw new LogicException("PaymentAllocation {$allocation->id} amount must be greater than zero.");
            }

            if ($payment->status === 'confirmed') {
                $allocated += $amount;
            }
        }

        return $allocated;
    }

    /** @param list<array<string, mixed>> $periods */
    private function totals(array $periods): array
    {
        $totals = [
            'period_count' => 0,
            'paid_period_count' => 0,
            'unpaid_period_count' => 0,
            'fully_unpaid_period_count' => 0,
            'partially_paid_period_count' => 0,
            'overdue_period_count' => 0,
            'current_period_count' => 0,
            'future_period_count' => 0,
            'past_period_count' => 0,
        ];
        $money = ['total' => 0, 'allocated' => 0, 'remaining' => 0, 'overdue_remaining' => 0];

        foreach ($periods as $period) {
            $totals['period_count']++;
            $totals[$period['payment_status'] === 'paid' ? 'paid_period_count' : 'unpaid_period_count']++;

            if ($period['payment_status'] === 'unpaid') {
                $totals['fully_unpaid_period_count']++;
            } elseif ($period['payment_status'] === 'partially_paid') {
                $totals['partially_paid_period_count']++;
            }

            $totals[$period['period_status'].'_period_count']++;
            if ($period['is_overdue']) {
                $totals['overdue_period_count']++;
            }

            foreach (['total', 'allocated', 'remaining'] as $key) {
                $money[$key] += $this->toMinorUnits($period[$key], "Period {$key}");
            }
            if ($period['is_overdue']) {
                $money['overdue_remaining'] += $this->toMinorUnits($period['remaining'], 'Overdue remaining');
            }
        }

        foreach ($money as $key => $value) {
            $totals[$key] = $this->formatMinorUnits($value);
        }

        return $totals;
    }

    private function requiredRelation(InvoiceLine $line, string $name, string $class, int $lineId): object
    {
        if (!$line->relationLoaded($name)) {
            throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        }

        $relation = $line->getRelation($name);
        if (!$relation instanceof $class) {
            throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        }

        return $relation;
    }

    private function requiredNullableRelation(
        InvoiceLine $line,
        string $name,
        string $class,
        int $lineId
    ): ?object {
        if (!$line->relationLoaded($name)) {
            throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        }

        $relation = $line->getRelation($name);
        if ($relation !== null && !$relation instanceof $class) {
            throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        }

        return $relation;
    }

    private function requiredIterableRelation(InvoiceLine $line, string $name, int $lineId): iterable
    {
        if (!$line->relationLoaded($name)) {
            throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        }

        $relation = $line->getRelation($name);
        if (!is_iterable($relation)) {
            throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        }

        return $relation;
    }

    private function periodStatus(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $asOf
    ): string {
        if ($end->lt($asOf)) {
            return 'past';
        }

        return $start->gt($asOf) ? 'future' : 'current';
    }

    private function paymentStatus(int $allocated, int $remaining): string
    {
        if ($remaining === 0) {
            return 'paid';
        }

        return $allocated === 0 ? 'unpaid' : 'partially_paid';
    }

    private function anomaly(int $lineId, string $type): array
    {
        return [
            'invoice_line_id' => $lineId,
            'type' => $type,
            'message' => "InvoiceLine {$lineId} was skipped because {$type}.",
        ];
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        }

        throw new LogicException("{$field} must be a valid YYYY-MM-DD date.");
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new LogicException("{$field} must be a positive integer.");
        }

        return (int) $value;
    }

    private function toMinorUnits(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new LogicException("{$field} must be finite.");
            }
            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        if (!is_string($value) || preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/', trim($value), $matches) !== 1) {
            throw new LogicException("{$field} must be a decimal with at most two places.");
        }

        if ($matches[1] === '-' && trim($matches[2].($matches[3] ?? ''), '0') !== '') {
            throw new LogicException("{$field} cannot be negative.");
        }

        return ((int) $matches[2] * 100)
            + (int) str_pad($matches[3] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
