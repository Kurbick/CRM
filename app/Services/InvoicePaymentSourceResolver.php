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
        // Payment allocations are NET line-level amounts. For VAT invoices,
        // source attribution is based on the confirmed payment's gross amount,
        // but that payment must be counted once even when it has allocations
        // for several invoice lines.
        $vatPayments = "(SELECT COALESCE(SUM(source_vat_payments.amount), 0)
            FROM payments AS source_vat_payments
            WHERE source_vat_payments.invoice_id = invoices.id
              AND source_vat_payments.status = 'confirmed')";
        $netAllocations = "(SELECT COALESCE(SUM(source_net_allocations.amount), 0)
            FROM payment_allocations AS source_net_allocations
            INNER JOIN payments AS source_net_payments
                ON source_net_payments.id = source_net_allocations.payment_id
            WHERE source_net_payments.invoice_id = invoices.id
              AND source_net_payments.status = 'confirmed')";
        $creditVatPayments = "(SELECT COALESCE(SUM(source_credit_vat_payments.amount), 0)
            FROM payments AS source_credit_vat_payments
            WHERE source_credit_vat_payments.invoice_id = invoices.id
              AND source_credit_vat_payments.status = 'confirmed'
              AND EXISTS (
                  SELECT 1
                  FROM credit_balance_entries AS source_applied_entries
                  WHERE source_applied_entries.payment_id = source_credit_vat_payments.id
                    AND source_applied_entries.invoice_id = source_credit_vat_payments.invoice_id
                    AND source_applied_entries.type = 'applied'
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM credit_balance_entries AS source_reversal_entries
                  WHERE source_reversal_entries.payment_id = source_credit_vat_payments.id
                    AND source_reversal_entries.invoice_id = source_credit_vat_payments.invoice_id
                    AND source_reversal_entries.type = 'applied_reversal'
              ))";
        $creditNetAllocations = "(SELECT COALESCE(SUM(source_credit_net_allocations.amount), 0)
            FROM payment_allocations AS source_credit_net_allocations
            INNER JOIN payments AS source_credit_net_payments
                ON source_credit_net_payments.id = source_credit_net_allocations.payment_id
            WHERE source_credit_net_payments.invoice_id = invoices.id
              AND source_credit_net_payments.status = 'confirmed'
              AND EXISTS (
                  SELECT 1
                  FROM credit_balance_entries AS source_applied_entries
                  WHERE source_applied_entries.payment_id = source_credit_net_payments.id
                    AND source_applied_entries.invoice_id = source_credit_net_payments.invoice_id
                    AND source_applied_entries.type = 'applied'
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM credit_balance_entries AS source_reversal_entries
                  WHERE source_reversal_entries.payment_id = source_credit_net_payments.id
                    AND source_reversal_entries.invoice_id = source_credit_net_payments.invoice_id
                    AND source_reversal_entries.type = 'applied_reversal'
              ))";

        return $query->addSelect([
            self::TOTAL_APPLIED_AGGREGATE => DB::raw("(CASE WHEN invoices.vat_enabled = 1 THEN {$vatPayments} ELSE {$netAllocations} END) AS ".self::TOTAL_APPLIED_AGGREGATE),
            self::CREDIT_APPLIED_AGGREGATE => DB::raw("(CASE WHEN invoices.vat_enabled = 1 THEN {$creditVatPayments} ELSE {$creditNetAllocations} END) AS ".self::CREDIT_APPLIED_AGGREGATE),
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

            $paymentSourceMinor = $invoice->vat_enabled
                ? $this->toMinorUnits($payment->getRawOriginal('amount') ?: $payment->amount)
                : $paymentAppliedMinor;
            $totalAppliedMinor += $paymentSourceMinor;

            if ($paymentSourceMinor > 0 && $this->isActiveCreditBalancePayment($payment, (int) $invoice->getKey())) {
                $creditAppliedMinor += $paymentSourceMinor;
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
