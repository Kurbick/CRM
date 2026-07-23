<?php

namespace App\Services;

use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoicePaymentSourceResolver
{
    public const TOTAL_APPLIED_AGGREGATE = 'source_total_applied_amount';

    public const CREDIT_APPLIED_AGGREGATE = 'source_credit_balance_applied_amount';

    /**
     * Add both source totals as correlated subqueries without loading payment history.
     */
    public function addAggregates(Builder $query): Builder
    {
        return $query->addSelect([
            self::TOTAL_APPLIED_AGGREGATE => DB::table('payment_allocations as source_allocations')
                ->join('payments as source_payments', 'source_payments.id', '=', 'source_allocations.payment_id')
                ->selectRaw('COALESCE(SUM(source_allocations.amount), 0)')
                ->whereColumn('source_payments.invoice_id', 'invoices.id')
                ->where('source_payments.status', 'confirmed'),
            self::CREDIT_APPLIED_AGGREGATE => DB::table('payment_allocations as source_credit_allocations')
                ->join('payments as source_credit_payments', 'source_credit_payments.id', '=', 'source_credit_allocations.payment_id')
                ->selectRaw('COALESCE(SUM(source_credit_allocations.amount), 0)')
                ->whereColumn('source_credit_payments.invoice_id', 'invoices.id')
                ->where('source_credit_payments.status', 'confirmed')
                ->whereExists(function ($entryQuery): void {
                    $entryQuery->selectRaw('1')
                        ->from('credit_balance_entries as source_applied_entries')
                        ->whereColumn('source_applied_entries.payment_id', 'source_credit_payments.id')
                        ->whereColumn('source_applied_entries.invoice_id', 'source_credit_payments.invoice_id')
                        ->where('source_applied_entries.type', 'applied');
                })
                ->whereNotExists(function ($entryQuery): void {
                    $entryQuery->selectRaw('1')
                        ->from('credit_balance_entries as source_reversal_entries')
                        ->whereColumn('source_reversal_entries.payment_id', 'source_credit_payments.id')
                        ->whereColumn('source_reversal_entries.invoice_id', 'source_credit_payments.invoice_id')
                        ->where('source_reversal_entries.type', 'applied_reversal');
                }),
        ]);
    }

    /**
     * @return array{total_applied_minor: int, credit_balance_applied_minor: int, credit_balance_applied_amount: string, state: null|'full'|'partial', credit_balance_payment_ids: list<int>}
     */
    public function fromLoadedInvoice(Invoice $invoice): array
    {
        if (!$invoice->relationLoaded('payments')) {
            throw new LogicException('Invoice payments relation must be loaded.');
        }

        $totalAppliedMinor = 0;
        $creditAppliedMinor = 0;
        $creditPaymentIds = [];

        foreach ($invoice->getRelation('payments') as $payment) {
            if (!$payment instanceof Payment) {
                throw new LogicException('Invoice payments relation must contain Payment models.');
            }

            if (!$payment->relationLoaded('allocations') || !$payment->relationLoaded('creditBalanceEntries')) {
                throw new LogicException('Payment allocations and creditBalanceEntries relations must be loaded.');
            }

            if ($payment->status !== 'confirmed') {
                continue;
            }

            $paymentAppliedMinor = 0;
            foreach ($payment->getRelation('allocations') as $allocation) {
                if (!$allocation instanceof PaymentAllocation) {
                    throw new LogicException('Payment allocations relation must contain PaymentAllocation models.');
                }

                $paymentAppliedMinor += $this->toMinorUnits($allocation->amount);
            }

            $totalAppliedMinor += $paymentAppliedMinor;

            if ($paymentAppliedMinor > 0 && $this->isActiveCreditBalancePayment($payment, (int) $invoice->getKey())) {
                $creditAppliedMinor += $paymentAppliedMinor;
                $creditPaymentIds[] = (int) $payment->getKey();
            }
        }

        return $this->result($totalAppliedMinor, $creditAppliedMinor, $creditPaymentIds);
    }

    /**
     * @return array{total_applied_minor: int, credit_balance_applied_minor: int, credit_balance_applied_amount: string, state: null|'full'|'partial', credit_balance_payment_ids: list<int>}
     */
    public function fromAggregates(Invoice $invoice): array
    {
        return $this->result(
            $this->toMinorUnits($invoice->getAttribute(self::TOTAL_APPLIED_AGGREGATE) ?? '0.00'),
            $this->toMinorUnits($invoice->getAttribute(self::CREDIT_APPLIED_AGGREGATE) ?? '0.00'),
            []
        );
    }

    private function isActiveCreditBalancePayment(Payment $payment, int $invoiceId): bool
    {
        $entries = $payment->getRelation('creditBalanceEntries');
        $hasApplied = $entries->contains(
            fn($entry): bool => $entry instanceof CreditBalanceEntry
                && $entry->type === 'applied'
                && (int) $entry->invoice_id === $invoiceId
        );
        $hasReversal = $entries->contains(
            fn($entry): bool => $entry instanceof CreditBalanceEntry
                && $entry->type === 'applied_reversal'
                && (int) $entry->invoice_id === $invoiceId
        );

        return $hasApplied && !$hasReversal;
    }

    /**
     * @param list<int> $creditPaymentIds
     * @return array{total_applied_minor: int, credit_balance_applied_minor: int, credit_balance_applied_amount: string, state: null|'full'|'partial', credit_balance_payment_ids: list<int>}
     */
    private function result(int $totalAppliedMinor, int $creditAppliedMinor, array $creditPaymentIds): array
    {
        $state = null;
        if ($creditAppliedMinor > 0) {
            $state = $creditAppliedMinor === $totalAppliedMinor ? 'full' : 'partial';
        }

        return [
            'total_applied_minor' => $totalAppliedMinor,
            'credit_balance_applied_minor' => $creditAppliedMinor,
            'credit_balance_applied_amount' => $this->fromMinorUnits($creditAppliedMinor),
            'state' => $state,
            'credit_balance_payment_ids' => $creditPaymentIds,
        ];
    }

    private function fromMinorUnits(int $amount): string
    {
        $negative = $amount < 0;
        $absolute = abs($amount);
        $decimal = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$decimal : $decimal;
    }

    private function toMinorUnits(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new LogicException("Invalid monetary amount: {$value}");
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }
}
