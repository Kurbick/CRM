<?php

namespace App\Services;

use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

class InvoicePaymentBreakdownPresenter
{
    /**
     * Build a read-only view of the allocations already persisted for an invoice.
     *
     * @return array{lineRows: list<array<string, mixed>>, paymentRows: list<array<string, mixed>>, payments_count: int, pending_payments_count: int, confirmed_payments_count: int, cancelled_payments_count: int, latest_payment: ?array, totals: array<string, string|int>}
     */
    public function present(Invoice $invoice): array
    {
        $this->requireLoaded($invoice, 'lines');
        $this->requireLoaded($invoice, 'payments');

        $invoiceId = $this->positiveId($invoice->getKey(), 'Invoice id');
        $lines = $this->normaliseLines($invoice->getRelation('lines'), $invoiceId);
        $payments = $this->normalisePayments($invoice->getRelation('payments'), $invoiceId);
        $lineAmounts = [];
        $lineAllocated = [];
        $lineAllocationsCount = [];
        $paymentApplied = [];
        $paymentAllocations = [];
        $seenPairs = [];

        foreach ($lines as $line) {
            $lineAmounts[$line['id']] = $line['amount_minor'];
            $lineAllocated[$line['id']] = 0;
            $lineAllocationsCount[$line['id']] = 0;
        }

        foreach ($payments as $payment) {
            $paymentId = $payment['id'];
            $paymentApplied[$paymentId] = 0;
            $paymentAllocations[$paymentId] = [];

            foreach ($payment['model']->getRelation('allocations') as $allocation) {
                if (!$allocation instanceof PaymentAllocation) {
                    throw new LogicException("Payment {$paymentId} allocations must contain PaymentAllocation models.");
                }

                $allocationPaymentId = $this->positiveId(
                    $allocation->payment_id,
                    'Payment allocation payment_id'
                );
                $lineId = $this->positiveId(
                    $allocation->invoice_line_id,
                    'Payment allocation invoice_line_id'
                );

                if ($allocationPaymentId !== $paymentId) {
                    throw new LogicException("Payment allocation belongs to another payment.");
                }

                if (!array_key_exists($lineId, $lineAmounts)) {
                    throw new LogicException("Payment allocation references an invoice line outside the current invoice.");
                }

                if ($payment['status'] !== 'confirmed') {
                    throw new LogicException("Pending or cancelled payment {$paymentId} has current allocations.");
                }

                $pair = $paymentId.':'.$lineId;
                if (isset($seenPairs[$pair])) {
                    throw new LogicException("Duplicate payment allocation pair {$pair}.");
                }
                $seenPairs[$pair] = true;

                $amount = $this->toMinorUnits(
                    $allocation->amount,
                    "Payment allocation {$pair} amount"
                );
                if ($amount <= 0) {
                    throw new LogicException("Payment allocation {$pair} amount must be positive.");
                }

                $paymentApplied[$paymentId] += $amount;
                $lineAllocated[$lineId] += $amount;
                $lineAllocationsCount[$lineId]++;
                $paymentAllocations[$paymentId][] = [
                    'invoice_line_id' => $lineId,
                    'allocated_minor' => $amount,
                ];
            }

            if ($paymentApplied[$paymentId] > $payment['amount_minor']) {
                throw new LogicException("Payment {$paymentId} allocations exceed its amount.");
            }
        }

        foreach ($lineAmounts as $lineId => $amount) {
            if ($lineAllocated[$lineId] > $amount) {
                throw new LogicException("Invoice line {$lineId} allocations exceed its amount.");
            }
        }

        $lineRows = [];
        $lineRowsById = [];
        $linePositions = [];
        $invoiceLinesTotal = 0;
        $allocatedFromLines = 0;

        foreach ($lines as $line) {
            $paid = $lineAllocated[$line['id']];
            $remaining = max($line['amount_minor'] - $paid, 0);
            $state = $paid === 0
                ? 'unpaid'
                : ($remaining === 0 ? 'paid' : 'partially_paid');

            $row = [
                'id' => $line['id'],
                'description' => $line['description'],
                'type' => $line['type'],
                'type_label' => $line['type_label'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'period_label' => $line['period_label'],
                'amount' => $this->formatMinorUnits($line['amount_minor']),
                'paid_amount' => $this->formatMinorUnits($paid),
                'remaining_amount' => $this->formatMinorUnits($remaining),
                'payment_state' => $state,
                'payment_state_label' => match ($state) {
                    'paid' => 'Оплачено',
                    'partially_paid' => 'Частично',
                    default => 'Не оплачено',
                },
                'allocations_count' => $lineAllocationsCount[$line['id']],
            ];

            $lineRows[] = $row;
            $lineRowsById[$line['id']] = $row;
            $linePositions[$line['id']] = count($lineRows) - 1;
            $invoiceLinesTotal += $line['amount_minor'];
            $allocatedFromLines += $paid;
        }

        $paymentRows = [];
        $allocatedFromPayments = 0;
        $confirmedPaymentsTotal = 0;
        $unallocatedTotal = 0;

        foreach ($payments as $payment) {
            $paymentId = $payment['id'];
            $applied = $paymentApplied[$paymentId];
            $unallocated = $payment['status'] === 'confirmed'
                ? max($payment['amount_minor'] - $applied, 0)
                : 0;
            $allocations = [];

            usort(
                $paymentAllocations[$paymentId],
                static fn(array $left, array $right): int =>
                    $linePositions[$left['invoice_line_id']]
                    <=> $linePositions[$right['invoice_line_id']]
            );

            foreach ($paymentAllocations[$paymentId] as $allocation) {
                $line = $lineRowsById[$allocation['invoice_line_id']];
                $allocations[] = [
                    'invoice_line_id' => $line['id'],
                    'line_description' => $line['description'],
                    'line_type' => $line['type'],
                    'line_type_label' => $line['type_label'],
                    'period_label' => $line['period_label'],
                    'allocated_amount' => $this->formatMinorUnits($allocation['allocated_minor']),
                ];
            }

            $paymentRows[] = [
                'id' => $paymentId,
                'payment_date' => $payment['payment_date'],
                'payment_method' => $payment['payment_method'],
                'payment_method_label' => match ($payment['payment_method']) {
                    'transfer' => 'Безналичный',
                    'card' => 'Карта',
                    'cash' => 'Наличные',
                    default => $payment['payment_method'],
                },
                'status' => $payment['status'],
                'status_label' => match ($payment['status']) {
                    'confirmed' => 'Подтверждён',
                    'pending' => 'Ожидает',
                    'cancelled' => 'Отменён',
                    default => $payment['status'],
                },
                'amount' => $this->formatMinorUnits($payment['amount_minor']),
                'applied_amount' => $this->formatMinorUnits($applied),
                'unallocated_amount' => $this->formatMinorUnits($unallocated),
                'is_credit_balance' => $payment['is_credit_balance'],
                'allocations' => $allocations,
            ];

            $allocatedFromPayments += $applied;
            if ($payment['status'] === 'confirmed') {
                $confirmedPaymentsTotal += $payment['amount_minor'];
                $unallocatedTotal += $unallocated;
            }
        }

        if ($allocatedFromLines !== $allocatedFromPayments) {
            throw new LogicException('Invoice line allocated total does not equal payment applied total.');
        }

        if ($invoice->total_amount !== null) {
            $storedTotal = $this->toMinorUnits($invoice->total_amount, 'Invoice total_amount');
            if ($storedTotal !== $invoiceLinesTotal) {
                throw new LogicException('Invoice line total does not equal Invoice total_amount.');
            }
        }

        $expectedApplied = min($invoiceLinesTotal, $confirmedPaymentsTotal);
        if ($allocatedFromLines !== $expectedApplied) {
            throw new LogicException('Persisted allocations do not match current Invoice payment totals.');
        }

        $paymentStatusCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'cancelled' => 0,
        ];
        foreach ($paymentRows as $paymentRow) {
            if (array_key_exists($paymentRow['status'], $paymentStatusCounts)) {
                $paymentStatusCounts[$paymentRow['status']]++;
            }
        }

        return [
            'lineRows' => $lineRows,
            'paymentRows' => $paymentRows,
            'payments_count' => count($paymentRows),
            'pending_payments_count' => $paymentStatusCounts['pending'],
            'confirmed_payments_count' => $paymentStatusCounts['confirmed'],
            'cancelled_payments_count' => $paymentStatusCounts['cancelled'],
            'latest_payment' => $paymentRows[0] ?? null,
            'totals' => [
                'invoice_lines_total' => $this->formatMinorUnits($invoiceLinesTotal),
                'allocated_total' => $this->formatMinorUnits($allocatedFromLines),
                'remaining_total' => $this->formatMinorUnits(max($invoiceLinesTotal - $allocatedFromLines, 0)),
                'confirmed_payments_total' => $this->formatMinorUnits($confirmedPaymentsTotal),
                'unallocated_total' => $this->formatMinorUnits($unallocatedTotal),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(iterable $models, int $invoiceId): array
    {
        $lines = [];
        $ids = [];

        foreach ($models as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new LogicException('Invoice lines relation must contain InvoiceLine models.');
            }

            $id = $this->positiveId($line->getKey(), 'Invoice line id');
            if (isset($ids[$id])) {
                throw new LogicException("Duplicate invoice line id {$id}.");
            }
            if ((int) $line->invoice_id !== $invoiceId) {
                throw new LogicException("Invoice line {$id} belongs to another invoice.");
            }
            if ($line->subscription_id !== null && $line->order_id !== null) {
                throw new LogicException("Invoice line {$id} cannot be both a subscription and an order.");
            }

            $ids[$id] = true;
            $periodStart = $this->dateKey($line->period_start);
            $periodEnd = $this->dateKey($line->period_end);
            $type = $line->subscription_id !== null
                ? 'subscription'
                : ($line->order_id !== null ? 'order' : 'manual');

            $lines[] = [
                'id' => $id,
                'description' => trim((string) $line->description),
                'type' => $type,
                'type_label' => match ($type) {
                    'subscription' => 'Подписка',
                    'order' => 'Разовая услуга',
                    default => 'Ручная позиция',
                },
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'period_label' => $this->periodLabel($periodStart, $periodEnd),
                'amount_minor' => $this->toMinorUnits($line->amount, "Invoice line {$id} amount"),
            ];
        }

        usort($lines, static function (array $left, array $right): int {
            if ($left['period_start'] === null && $right['period_start'] !== null) {
                return 1;
            }
            if ($left['period_start'] !== null && $right['period_start'] === null) {
                return -1;
            }

            return [$left['period_start'] ?? '', $left['id']]
                <=> [$right['period_start'] ?? '', $right['id']];
        });

        return $lines;
    }

    private function periodLabel(?string $periodStart, ?string $periodEnd): ?string
    {
        if ($periodStart !== null && $periodEnd !== null) {
            return Carbon::parse($periodStart)->format('d/m/Y')
                .' — '.Carbon::parse($periodEnd)->format('d/m/Y');
        }

        if ($periodStart !== null) {
            return 'с '.Carbon::parse($periodStart)->format('d/m/Y');
        }

        if ($periodEnd !== null) {
            return 'до '.Carbon::parse($periodEnd)->format('d/m/Y');
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function normalisePayments(iterable $models, int $invoiceId): array
    {
        $payments = [];
        $ids = [];

        foreach ($models as $payment) {
            if (!$payment instanceof Payment) {
                throw new LogicException('Payments relation must contain Payment models.');
            }

            $this->requireLoaded($payment, 'allocations');
            $this->requireLoaded($payment, 'creditBalanceEntries');
            $id = $this->positiveId($payment->getKey(), 'Payment id');
            if (isset($ids[$id])) {
                throw new LogicException("Duplicate payment id {$id}.");
            }
            if ((int) $payment->invoice_id !== $invoiceId) {
                throw new LogicException("Payment {$id} belongs to another invoice.");
            }

            $ids[$id] = true;
            $payments[] = [
                'id' => $id,
                'payment_date' => $this->dateKey($payment->payment_date),
                'payment_method' => (string) $payment->payment_method,
                'status' => (string) $payment->status,
                'amount_minor' => $this->toMinorUnits($payment->amount, "Payment {$id} amount"),
                'is_credit_balance' => $payment->getRelation('creditBalanceEntries')
                    ->contains(fn($entry): bool => $entry instanceof CreditBalanceEntry && $entry->type === 'applied'),
                'model' => $payment,
            ];
        }

        usort($payments, static fn(array $left, array $right): int =>
            [$right['payment_date'] ?? '', $right['id']]
            <=> [$left['payment_date'] ?? '', $left['id']]
        );

        return $payments;
    }

    private function requireLoaded(Invoice|Payment $model, string $relation): void
    {
        if (!$model->relationLoaded($relation)) {
            throw new LogicException(sprintf(
                '%s relation %s must be eager loaded.',
                class_basename($model),
                $relation
            ));
        }

        if (!$model->getRelation($relation) instanceof Collection) {
            throw new LogicException(sprintf(
                '%s relation %s must be a collection.',
                class_basename($model),
                $relation
            ));
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new LogicException("{$field} must be a positive integer.");
        }

        return (int) $value;
    }

    private function dateKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        throw new LogicException('Date must use YYYY-MM-DD format.');
    }

    private function toMinorUnits(mixed $value, string $field): int
    {
        $normalised = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : null);

        if ($normalised === null || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalised, $matches) !== 1) {
            throw new LogicException("{$field} must be a non-negative monetary value with at most two decimals.");
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
