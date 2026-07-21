<?php

namespace App\Services;

use App\Models\InvoiceLine;
use App\Models\Payment;
use DateTimeInterface;
use InvalidArgumentException;

class InvoicePaymentAllocationCalculator
{
    /**
     * Distribute confirmed payments across one invoice's lines in FIFO order.
     *
     * Pending and cancelled payments are omitted from the result. Monetary
     * inputs are rounded half up to two decimal places at the boundary; all
     * allocation arithmetic is then performed in integer minor units.
     *
     * @param  iterable<InvoiceLine>  $invoiceLines
     * @param  iterable<Payment>  $payments
     * @return array{
     *     allocations: list<array{payment_id: int, invoice_line_id: int, amount: string}>,
     *     lines: array<int, array{total: string, allocated: string, remaining: string}>,
     *     payments: array<int, array{amount: string, allocated: string, unallocated: string}>,
     *     totals: array{
     *         line_total: string,
     *         confirmed_payment_total: string,
     *         applied_total: string,
     *         remaining_total: string,
     *         overpayment_total: string
     *     }
     * }
     */
    public function calculate(iterable $invoiceLines, iterable $payments): array
    {
        $lines = $this->normaliseLines($invoiceLines);
        $allPayments = $this->normalisePayments($payments);

        $this->assertSingleInvoice($lines, $allPayments);

        usort($lines, fn(array $left, array $right): int => $this->compareLines($left, $right));

        $confirmedPayments = array_values(array_filter(
            $allPayments,
            fn(array $payment): bool => $payment['model']->status === 'confirmed'
        ));

        usort(
            $confirmedPayments,
            fn(array $left, array $right): int => $this->comparePayments($left, $right)
        );

        $lineStates = [];
        $lineTotal = 0;

        foreach ($lines as $line) {
            $lineStates[$line['id']] = [
                'total' => $line['amount'],
                'allocated' => 0,
            ];
            $lineTotal += $line['amount'];
        }

        $allocations = [];
        $paymentStates = [];
        $confirmedPaymentTotal = 0;
        $lineIndex = 0;

        foreach ($confirmedPayments as $payment) {
            $paymentRemaining = $payment['amount'];
            $paymentAllocated = 0;
            $confirmedPaymentTotal += $payment['amount'];

            while ($paymentRemaining > 0 && isset($lines[$lineIndex])) {
                $lineId = $lines[$lineIndex]['id'];
                $lineRemaining = $lineStates[$lineId]['total']
                    - $lineStates[$lineId]['allocated'];

                if ($lineRemaining <= 0) {
                    $lineIndex++;
                    continue;
                }

                $allocated = min($paymentRemaining, $lineRemaining);

                if ($allocated > 0) {
                    $allocations[] = [
                        'payment_id' => $payment['id'],
                        'invoice_line_id' => $lineId,
                        'amount' => $this->formatMinorUnits($allocated),
                    ];

                    $lineStates[$lineId]['allocated'] += $allocated;
                    $paymentAllocated += $allocated;
                    $paymentRemaining -= $allocated;
                }

                if ($lineStates[$lineId]['allocated'] >= $lineStates[$lineId]['total']) {
                    $lineIndex++;
                }
            }

            $paymentStates[$payment['id']] = [
                'amount' => $this->formatMinorUnits($payment['amount']),
                'allocated' => $this->formatMinorUnits($paymentAllocated),
                'unallocated' => $this->formatMinorUnits($paymentRemaining),
            ];
        }

        $formattedLines = [];
        $appliedTotal = 0;

        foreach ($lines as $line) {
            $state = $lineStates[$line['id']];
            $remaining = $state['total'] - $state['allocated'];
            $appliedTotal += $state['allocated'];

            $formattedLines[$line['id']] = [
                'total' => $this->formatMinorUnits($state['total']),
                'allocated' => $this->formatMinorUnits($state['allocated']),
                'remaining' => $this->formatMinorUnits($remaining),
            ];
        }

        return [
            'allocations' => $allocations,
            'lines' => $formattedLines,
            'payments' => $paymentStates,
            'totals' => [
                'line_total' => $this->formatMinorUnits($lineTotal),
                'confirmed_payment_total' => $this->formatMinorUnits($confirmedPaymentTotal),
                'applied_total' => $this->formatMinorUnits($appliedTotal),
                'remaining_total' => $this->formatMinorUnits($lineTotal - $appliedTotal),
                'overpayment_total' => $this->formatMinorUnits(
                    max(0, $confirmedPaymentTotal - $lineTotal)
                ),
            ],
        ];
    }

    /** @return list<array{id: int, invoice_id: int, amount: int, period_start: ?string, model: InvoiceLine}> */
    private function normaliseLines(iterable $invoiceLines): array
    {
        $lines = [];
        $ids = [];

        foreach ($invoiceLines as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new InvalidArgumentException('Every invoice line must be an InvoiceLine instance.');
            }

            $id = $this->positiveId($line->id, 'Invoice line id');

            if (isset($ids[$id])) {
                throw new InvalidArgumentException("Duplicate invoice line id: {$id}.");
            }

            $ids[$id] = true;
            $lines[] = [
                'id' => $id,
                'invoice_id' => $this->positiveId($line->invoice_id, 'Invoice line invoice_id'),
                'amount' => $this->toMinorUnits($line->amount, "Invoice line {$id} amount"),
                'period_start' => $this->dateKey($line->period_start),
                'model' => $line,
            ];
        }

        return $lines;
    }

    /** @return list<array{id: int, invoice_id: int, amount: int, payment_date: string, model: Payment}> */
    private function normalisePayments(iterable $payments): array
    {
        $normalised = [];
        $ids = [];

        foreach ($payments as $payment) {
            if (!$payment instanceof Payment) {
                throw new InvalidArgumentException('Every payment must be a Payment instance.');
            }

            $id = $this->positiveId($payment->id, 'Payment id');

            if (isset($ids[$id])) {
                throw new InvalidArgumentException("Duplicate payment id: {$id}.");
            }

            $ids[$id] = true;
            $normalised[] = [
                'id' => $id,
                'invoice_id' => $this->positiveId($payment->invoice_id, 'Payment invoice_id'),
                'amount' => $this->toMinorUnits($payment->amount, "Payment {$id} amount"),
                'payment_date' => $this->requiredDateKey($payment->payment_date, "Payment {$id} payment_date"),
                'model' => $payment,
            ];
        }

        return $normalised;
    }

    private function assertSingleInvoice(array $lines, array $payments): void
    {
        $invoiceIds = [];

        foreach (array_merge($lines, $payments) as $item) {
            $invoiceIds[$item['invoice_id']] = true;
        }

        if (count($invoiceIds) > 1) {
            throw new InvalidArgumentException('Invoice lines and payments must belong to one invoice.');
        }
    }

    private function compareLines(array $left, array $right): int
    {
        if ($left['period_start'] === null && $right['period_start'] !== null) {
            return 1;
        }

        if ($left['period_start'] !== null && $right['period_start'] === null) {
            return -1;
        }

        if ($left['period_start'] !== $right['period_start']) {
            return ($left['period_start'] ?? '') <=> ($right['period_start'] ?? '');
        }

        return $left['id'] <=> $right['id'];
    }

    private function comparePayments(array $left, array $right): int
    {
        return [$left['payment_date'], $left['id']]
            <=> [$right['payment_date'], $right['id']];
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        return (int) $value;
    }

    private function dateKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredDateKey($value, 'Date');
    }

    private function requiredDateKey(mixed $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        throw new InvalidArgumentException("{$field} must be a valid YYYY-MM-DD date.");
    }

    private function toMinorUnits(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $normalised = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException("{$field} must be a finite monetary value.");
            }

            $normalised = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $normalised = trim($value);
        } else {
            throw new InvalidArgumentException("{$field} must be a numeric monetary value.");
        }

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $normalised, $matches) !== 1) {
            throw new InvalidArgumentException("{$field} must be a numeric monetary value.");
        }

        $negative = $matches[1] === '-';
        $whole = (int) $matches[2];
        $fraction = $matches[3] ?? '';

        if ($negative && trim($matches[2].$fraction, '0') !== '') {
            throw new InvalidArgumentException("{$field} cannot be negative.");
        }

        $minorUnits = ($whole * 100)
            + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if (isset($fraction[2]) && (int) $fraction[2] >= 5) {
            $minorUnits++;
        }

        return $minorUnits;
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
