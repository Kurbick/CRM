<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

class InvoicePaymentAllocationWriter
{
    public function __construct(
        private readonly InvoicePaymentAllocationCalculator $calculator
    ) {
    }

    /**
     * Recalculate and synchronize persisted allocations for one invoice.
     *
     * Future payment confirm/cancel operations must call this service while
     * following the same invoice-row locking convention. The locked invoice
     * serializes allocation recalculation for that invoice.
     *
     * @return array{
     *     invoice_id: int,
     *     calculation: array,
     *     changes: array{created: int, updated: int, deleted: int, unchanged: int},
     *     allocation_count: int
     * }
     */
    public function synchronize(Invoice $invoice): array
    {
        if (!$invoice->exists || $invoice->getKey() === null) {
            throw new InvalidArgumentException('Invoice must exist in the database before allocations can be synchronized.');
        }

        $invoiceId = (int) $invoice->getKey();

        return DB::transaction(function () use ($invoiceId): array {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $lines = InvoiceLine::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payments = Payment::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $existingAllocations = $this->loadRelevantAllocations($payments, $lines);
            $this->assertExistingAllocationsBelongToInvoice(
                $existingAllocations,
                $lockedInvoice->getKey()
            );

            $calculation = $this->calculator->calculate($lines, $payments);
            $desired = $this->validateCalculation($calculation, $payments, $lines);
            $changes = $this->synchronizeAllocations($existingAllocations, $desired);

            return [
                'invoice_id' => (int) $lockedInvoice->getKey(),
                'calculation' => $calculation,
                'changes' => $changes,
                'allocation_count' => count($desired),
            ];
        });
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, InvoiceLine>  $lines
     * @return Collection<int, PaymentAllocation>
     */
    private function loadRelevantAllocations(Collection $payments, Collection $lines): Collection
    {
        $paymentIds = $payments->modelKeys();
        $lineIds = $lines->modelKeys();

        if ($paymentIds === [] && $lineIds === []) {
            return new Collection();
        }

        $allocations = PaymentAllocation::query()
            ->where(function ($query) use ($paymentIds, $lineIds): void {
                if ($paymentIds !== []) {
                    $query->whereIn('payment_id', $paymentIds);
                }

                if ($lineIds !== []) {
                    $method = $paymentIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('invoice_line_id', $lineIds);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($allocations->isEmpty()) {
            return $allocations;
        }

        $relatedPayments = Payment::query()
            ->whereIn('id', $allocations->pluck('payment_id')->unique()->values())
            ->lockForUpdate()
            ->get()
            ->keyBy(fn(Payment $payment): int => (int) $payment->getKey());

        $relatedLines = InvoiceLine::query()
            ->whereIn('id', $allocations->pluck('invoice_line_id')->unique()->values())
            ->lockForUpdate()
            ->get()
            ->keyBy(fn(InvoiceLine $line): int => (int) $line->getKey());

        foreach ($allocations as $allocation) {
            $payment = $relatedPayments->get((int) $allocation->payment_id);
            $line = $relatedLines->get((int) $allocation->invoice_line_id);

            if ($payment !== null) {
                $allocation->setRelation('payment', $payment);
            }

            if ($line !== null) {
                $allocation->setRelation('invoiceLine', $line);
            }
        }

        return $allocations;
    }

    /** @param Collection<int, PaymentAllocation> $allocations */
    private function assertExistingAllocationsBelongToInvoice(
        Collection $allocations,
        int|string $invoiceId
    ): void {
        $expectedInvoiceId = (int) $invoiceId;

        foreach ($allocations as $allocation) {
            $payment = $allocation->relationLoaded('payment')
                ? $allocation->getRelation('payment')
                : null;
            $line = $allocation->relationLoaded('invoiceLine')
                ? $allocation->getRelation('invoiceLine')
                : null;
            $paymentInvoiceId = $payment instanceof Payment ? (int) $payment->invoice_id : null;
            $lineInvoiceId = $line instanceof InvoiceLine ? (int) $line->invoice_id : null;

            if (
                !$payment instanceof Payment
                || !$line instanceof InvoiceLine
                || $paymentInvoiceId !== $lineInvoiceId
                || $paymentInvoiceId !== $expectedInvoiceId
            ) {
                throw new LogicException(sprintf(
                    'Invalid payment allocation %d: payment %d belongs to invoice %s, invoice line %d belongs to invoice %s; expected invoice %d.',
                    (int) $allocation->getKey(),
                    (int) $allocation->payment_id,
                    $paymentInvoiceId === null ? 'missing' : (string) $paymentInvoiceId,
                    (int) $allocation->invoice_line_id,
                    $lineInvoiceId === null ? 'missing' : (string) $lineInvoiceId,
                    $expectedInvoiceId
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $calculation
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, array{payment_id: int, invoice_line_id: int, amount: string, minor: int}>
     */
    private function validateCalculation(
        array $calculation,
        Collection $payments,
        Collection $lines
    ): array {
        if (!isset($calculation['allocations'], $calculation['totals']) || !is_array($calculation['allocations'])) {
            throw new UnexpectedValueException('Allocation calculator returned an invalid result structure.');
        }

        $paymentMap = $payments->keyBy(fn(Payment $payment): int => (int) $payment->getKey());
        $lineMap = $lines->keyBy(fn(InvoiceLine $line): int => (int) $line->getKey());
        $desired = [];
        $paymentSums = [];
        $lineSums = [];
        $allocationTotal = 0;
        $actualLineTotal = 0;
        $actualConfirmedPaymentTotal = 0;

        foreach ($calculation['allocations'] as $allocation) {
            if (!is_array($allocation)) {
                throw new UnexpectedValueException('Each calculated allocation must be an array.');
            }

            $paymentId = $this->positiveId($allocation['payment_id'] ?? null, 'Calculated payment_id');
            $lineId = $this->positiveId($allocation['invoice_line_id'] ?? null, 'Calculated invoice_line_id');
            $payment = $paymentMap->get($paymentId);
            $line = $lineMap->get($lineId);

            if (!$payment instanceof Payment || !$line instanceof InvoiceLine) {
                throw new LogicException("Calculated allocation references payment {$paymentId} or invoice line {$lineId} outside the invoice.");
            }

            if ($payment->status !== 'confirmed') {
                throw new LogicException("Calculated allocation references non-confirmed payment {$paymentId}.");
            }

            $minor = $this->toMinorUnits($allocation['amount'] ?? null, 'Calculated allocation amount');

            if ($minor <= 0) {
                throw new LogicException('Calculated allocation amount must be greater than zero.');
            }

            $key = $paymentId.':'.$lineId;

            if (isset($desired[$key])) {
                throw new LogicException("Calculated allocation pair {$key} is duplicated.");
            }

            $amount = $this->formatMinorUnits($minor);
            $desired[$key] = [
                'payment_id' => $paymentId,
                'invoice_line_id' => $lineId,
                'amount' => $amount,
                'minor' => $minor,
            ];

            $paymentSums[$paymentId] = ($paymentSums[$paymentId] ?? 0) + $minor;
            $lineSums[$lineId] = ($lineSums[$lineId] ?? 0) + $minor;
            $allocationTotal += $minor;
        }

        foreach ($payments as $payment) {
            $paymentId = (int) $payment->getKey();
            $allocated = $paymentSums[$paymentId] ?? 0;
            $paymentAmount = $this->toMinorUnits($payment->amount, "Payment {$paymentId} amount");

            if ($payment->status !== 'confirmed' && $allocated !== 0) {
                throw new LogicException("Pending or cancelled payment {$paymentId} has calculated allocations.");
            }

            if ($allocated > $paymentAmount) {
                throw new LogicException("Calculated allocations exceed payment {$paymentId} amount.");
            }

            if ($payment->status === 'confirmed') {
                $actualConfirmedPaymentTotal += $paymentAmount;
            }
        }

        foreach ($lines as $line) {
            $lineId = (int) $line->getKey();
            $lineAmount = $this->toMinorUnits($line->amount, "Invoice line {$lineId} amount");

            if (($lineSums[$lineId] ?? 0) > $lineAmount) {
                throw new LogicException("Calculated allocations exceed invoice line {$lineId} amount.");
            }

            $actualLineTotal += $lineAmount;
        }

        $appliedTotal = $this->totalMinorUnits($calculation, 'applied_total');
        $lineTotal = $this->totalMinorUnits($calculation, 'line_total');
        $confirmedPaymentTotal = $this->totalMinorUnits($calculation, 'confirmed_payment_total');

        if ($allocationTotal !== $appliedTotal) {
            throw new LogicException('Calculated allocation sum does not equal applied_total.');
        }

        if ($lineTotal !== $actualLineTotal || $confirmedPaymentTotal !== $actualConfirmedPaymentTotal) {
            throw new LogicException('Calculator totals do not match the locked invoice lines and payments.');
        }

        if ($allocationTotal > $lineTotal || $allocationTotal > $confirmedPaymentTotal) {
            throw new LogicException('Calculated allocation sum exceeds calculator totals.');
        }

        return $desired;
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $existing
     * @param  array<string, array{payment_id: int, invoice_line_id: int, amount: string, minor: int}>  $desired
     * @return array{created: int, updated: int, deleted: int, unchanged: int}
     */
    private function synchronizeAllocations(Collection $existing, array $desired): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];

        foreach ($existing as $allocation) {
            $key = (int) $allocation->payment_id.':'.(int) $allocation->invoice_line_id;
            $target = $desired[$key] ?? null;

            if ($target === null) {
                $allocation->delete();
                $changes['deleted']++;
                continue;
            }

            if ($this->toMinorUnits($allocation->amount, "Payment allocation {$allocation->getKey()} amount") !== $target['minor']) {
                $allocation->amount = $target['amount'];
                $allocation->save();
                $changes['updated']++;
            } else {
                $changes['unchanged']++;
            }

            unset($desired[$key]);
        }

        foreach ($desired as $target) {
            PaymentAllocation::query()->create([
                'payment_id' => $target['payment_id'],
                'invoice_line_id' => $target['invoice_line_id'],
                'amount' => $target['amount'],
            ]);
            $changes['created']++;
        }

        return $changes;
    }

    /** @param array<string, mixed> $calculation */
    private function totalMinorUnits(array $calculation, string $key): int
    {
        if (!isset($calculation['totals']) || !is_array($calculation['totals']) || !array_key_exists($key, $calculation['totals'])) {
            throw new UnexpectedValueException("Allocation calculator result is missing totals.{$key}.");
        }

        return $this->toMinorUnits($calculation['totals'][$key], "Calculator total {$key}");
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new UnexpectedValueException("{$field} must be a positive integer.");
        }

        return (int) $value;
    }

    private function toMinorUnits(mixed $value, string $field): int
    {
        $value = is_int($value) ? (string) $value : $value;

        if (!is_string($value) || preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $matches) !== 1) {
            throw new UnexpectedValueException("{$field} must be a non-negative decimal with at most two decimal places.");
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
