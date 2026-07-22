<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;

class OneTimeServiceDebtCalculator
{
    private const INCLUDED_INVOICE_STATUSES = ['issued', 'partially_paid', 'paid'];

    /**
     * Calculate debt for order-backed and manual non-subscription invoice lines.
     * All relations must be loaded by the caller; the result contains plain values only.
     *
     * @param  iterable<InvoiceLine>  $invoiceLines
     * @return array{lines: list<array<string, mixed>>, totals: array<string, int|string>}
     */
    public function calculate(iterable $invoiceLines, CarbonInterface $asOf): array
    {
        $asOfDate = CarbonImmutable::instance($asOf)->startOfDay();
        $lines = [];

        foreach ($invoiceLines as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new InvalidArgumentException('Every invoice line must be an InvoiceLine instance.');
            }

            $lineId = $this->positiveId($line->id, 'InvoiceLine id');
            $invoice = $this->requiredRelation($line, 'invoice', Invoice::class, $lineId);
            $order = $this->requiredNullableRelation($line, 'order', Order::class, $lineId);
            $allocations = $this->requiredIterableRelation($line, 'allocations', $lineId);

            if ($line->subscription_id !== null && $line->order_id !== null) {
                throw new LogicException("InvoiceLine {$lineId} cannot reference both subscription and order.");
            }

            if ($line->subscription_id !== null) {
                continue;
            }

            if (!in_array($invoice->status, self::INCLUDED_INVOICE_STATUSES, true)) {
                continue;
            }

            $invoiceId = $this->positiveId($line->invoice_id, "InvoiceLine {$lineId} invoice_id");
            if ((int) $invoice->id !== $invoiceId) {
                throw new LogicException("InvoiceLine {$lineId} belongs to invoice {$invoiceId}, but its loaded invoice relation is {$invoice->id}.");
            }

            if ($line->order_id !== null) {
                $orderId = $this->positiveId($line->order_id, "InvoiceLine {$lineId} order_id");
                if (!$order instanceof Order || (int) $order->id !== $orderId) {
                    throw new LogicException("InvoiceLine {$lineId} has an invalid order relation for order_id {$orderId}.");
                }
            } else {
                $orderId = null;
                if ($order !== null) {
                    throw new LogicException("InvoiceLine {$lineId} has an order relation without order_id.");
                }
            }

            $total = $this->toMinorUnits($line->amount, "InvoiceLine {$lineId} amount");
            $allocated = $this->confirmedAllocatedMinor($allocations, $invoiceId, $lineId);
            if ($allocated > $total) {
                throw new LogicException("Confirmed allocations ({$this->formatMinorUnits($allocated)}) exceed InvoiceLine {$lineId} amount ({$this->formatMinorUnits($total)}).");
            }

            $remaining = $total - $allocated;
            $dueDate = $invoice->due_date === null || $invoice->due_date === ''
                ? null
                : $this->date($invoice->due_date, "Invoice {$invoiceId} due_date");
            $issueDate = $invoice->issue_date === null || $invoice->issue_date === ''
                ? null
                : $this->date($invoice->issue_date, "Invoice {$invoiceId} issue_date");
            $isOverdue = $remaining > 0 && $dueDate !== null && $dueDate->lt($asOfDate);

            $lines[] = [
                'invoice_line_id' => $lineId,
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) $invoice->invoice_number,
                'invoice_status' => (string) $invoice->status,
                'company_id' => $this->positiveId($invoice->company_id, "Invoice {$invoiceId} company_id"),
                'contract_id' => $invoice->contract_id === null ? null : $this->positiveId($invoice->contract_id, "Invoice {$invoiceId} contract_id"),
                'order_id' => $orderId,
                'service_title' => $this->serviceTitle($order, $line),
                'issue_date' => $issueDate?->toDateString(),
                'due_date' => $dueDate?->toDateString(),
                'total' => $this->formatMinorUnits($total),
                'allocated' => $this->formatMinorUnits($allocated),
                'remaining' => $this->formatMinorUnits($remaining),
                'payment_status' => $remaining === 0 ? 'paid' : ($allocated === 0 ? 'unpaid' : 'partially_paid'),
                'is_overdue' => $isOverdue,
                'days_overdue' => $isOverdue ? (int) $dueDate->diffInDays($asOfDate) : 0,
            ];
        }

        usort($lines, fn(array $left, array $right): int => [
            $left['due_date'] === null, $left['due_date'] ?? '', $left['issue_date'] ?? '', $left['invoice_id'], $left['invoice_line_id'],
        ] <=> [
            $right['due_date'] === null, $right['due_date'] ?? '', $right['issue_date'] ?? '', $right['invoice_id'], $right['invoice_line_id'],
        ]);

        return ['lines' => $lines, 'totals' => $this->totals($lines)];
    }

    private function confirmedAllocatedMinor(iterable $allocations, int $invoiceId, int $lineId): int
    {
        $allocated = 0;
        foreach ($allocations as $allocation) {
            if (!$allocation instanceof PaymentAllocation) {
                throw new LogicException("InvoiceLine {$lineId} allocations must contain PaymentAllocation models.");
            }
            if (!$allocation->relationLoaded('payment')) {
                throw new LogicException("PaymentAllocation {$allocation->id} for InvoiceLine {$lineId} is missing loaded relation payment.");
            }
            $payment = $allocation->getRelation('payment');
            if (!$payment instanceof Payment) {
                throw new LogicException("PaymentAllocation {$allocation->id} has an invalid payment relation.");
            }
            if ((int) $allocation->invoice_line_id !== $lineId) {
                throw new LogicException("PaymentAllocation {$allocation->id} references InvoiceLine {$allocation->invoice_line_id}, expected {$lineId}.");
            }
            if ((int) $payment->invoice_id !== $invoiceId) {
                throw new LogicException("PaymentAllocation {$allocation->id} payment {$payment->id} belongs to invoice {$payment->invoice_id}, expected {$invoiceId}.");
            }
            $amount = $this->toMinorUnits($allocation->amount, "PaymentAllocation {$allocation->id} amount");
            if ($amount <= 0) {
                throw new LogicException("PaymentAllocation {$allocation->id} amount must be greater than zero.");
            }
            if ($payment->status === 'confirmed') {
                $allocated += $amount;
            }
        }
        return $allocated;
    }

    /** @param list<array<string, mixed>> $lines */
    private function totals(array $lines): array
    {
        $totals = ['line_count' => 0, 'paid_line_count' => 0, 'unpaid_line_count' => 0, 'fully_unpaid_line_count' => 0, 'partially_paid_line_count' => 0, 'overdue_line_count' => 0];
        $money = ['total' => 0, 'allocated' => 0, 'remaining' => 0, 'overdue_remaining' => 0];
        foreach ($lines as $line) {
            $totals['line_count']++;
            $totals[$line['payment_status'] === 'paid' ? 'paid_line_count' : 'unpaid_line_count']++;
            if ($line['payment_status'] === 'unpaid') $totals['fully_unpaid_line_count']++;
            if ($line['payment_status'] === 'partially_paid') $totals['partially_paid_line_count']++;
            if ($line['is_overdue']) $totals['overdue_line_count']++;
            foreach (['total', 'allocated', 'remaining'] as $key) $money[$key] += $this->toMinorUnits($line[$key], "Line {$key}");
            if ($line['is_overdue']) $money['overdue_remaining'] += $this->toMinorUnits($line['remaining'], 'Overdue remaining');
        }
        foreach ($money as $key => $value) $totals[$key] = $this->formatMinorUnits($value);
        return $totals;
    }

    private function serviceTitle(?Order $order, InvoiceLine $line): string
    {
        foreach ([$order?->title, $line->description] as $title) {
            if (is_string($title) && trim($title) !== '') return trim($title);
        }
        return 'Разовая услуга';
    }

    private function requiredRelation(InvoiceLine $line, string $name, string $class, int $lineId): object
    {
        if (!$line->relationLoaded($name)) throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        $relation = $line->getRelation($name);
        if (!$relation instanceof $class) throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        return $relation;
    }

    private function requiredNullableRelation(InvoiceLine $line, string $name, string $class, int $lineId): ?object
    {
        if (!$line->relationLoaded($name)) throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        $relation = $line->getRelation($name);
        if ($relation !== null && !$relation instanceof $class) throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        return $relation;
    }

    private function requiredIterableRelation(InvoiceLine $line, string $name, int $lineId): iterable
    {
        if (!$line->relationLoaded($name)) throw new LogicException("InvoiceLine {$lineId} is missing loaded relation {$name}.");
        $relation = $line->getRelation($name);
        if (!is_iterable($relation)) throw new LogicException("InvoiceLine {$lineId} has an invalid {$name} relation.");
        return $relation;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) return CarbonImmutable::instance($value)->startOfDay();
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        throw new LogicException("{$field} must be a valid YYYY-MM-DD date.");
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) throw new LogicException("{$field} must be a positive integer.");
        return (int) $value;
    }

    private function toMinorUnits(mixed $value, string $field): int
    {
        if (is_int($value)) $value = (string) $value;
        elseif (is_float($value)) {
            if (!is_finite($value)) throw new LogicException("{$field} must be finite.");
            $value = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }
        if (!is_string($value) || preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/', trim($value), $matches) !== 1) throw new LogicException("{$field} must be a decimal with at most two places.");
        if ($matches[1] === '-' && trim($matches[2].($matches[3] ?? ''), '0') !== '') throw new LogicException("{$field} cannot be negative.");
        return ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
