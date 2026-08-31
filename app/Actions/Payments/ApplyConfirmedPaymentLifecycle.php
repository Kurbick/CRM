<?php

namespace App\Actions\Payments;

use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ApplyConfirmedPaymentLifecycle
{
    private const MAX_AMOUNT_MINOR = 9_999_999_999;

    public function __construct(
        private readonly InvoicePaymentAvailabilityService $money,
        private readonly InvoicePaymentAllocationWriter $allocationWriter
    ) {}

    public function execute(Invoice $invoice, Payment $payment): Payment
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Confirmed Payment lifecycle requires an active transaction.');
        }

        if (! $invoice->exists || ! $payment->exists) {
            throw new LogicException('Confirmed Payment lifecycle requires persisted models.');
        }

        if ((int) $payment->invoice_id !== (int) $invoice->getKey()) {
            throw new LogicException('Payment does not belong to the lifecycle Invoice.');
        }

        if ((int) $payment->company_id !== (int) $invoice->company_id) {
            throw new LogicException('Payment and Invoice companies do not match.');
        }

        if ($payment->status !== 'confirmed') {
            throw new LogicException('Confirmed Payment lifecycle requires confirmed status.');
        }

        $paymentMinor = $this->money->toMinorUnits($payment->getRawOriginal('amount'));
        if ($paymentMinor < 1 || $paymentMinor > self::MAX_AMOUNT_MINOR) {
            throw new LogicException('Confirmed Payment amount is outside the supported range.');
        }

        $invoiceTotalMinor = $this->money->toMinorUnits($invoice->getRawOriginal('total_amount'));
        $confirmedTotalMinor = $this->confirmedPositiveTotalMinor($invoice);

        $invoiceStatus = match (true) {
            $confirmedTotalMinor >= $invoiceTotalMinor => 'paid',
            $confirmedTotalMinor > 0 => 'partially_paid',
            default => 'issued',
        };

        if ($invoice->status !== $invoiceStatus) {
            $invoice->forceFill(['status' => $invoiceStatus])->saveQuietly();
        }

        $afterExcessMinor = max($confirmedTotalMinor - $invoiceTotalMinor, 0);
        $beforeExcessMinor = max(
            $confirmedTotalMinor - $paymentMinor - $invoiceTotalMinor,
            0
        );
        $newExcessMinor = max($afterExcessMinor - $beforeExcessMinor, 0);

        if ($newExcessMinor > 0) {
            $this->topUpCreditBalance($invoice, $payment, $newExcessMinor);
        }

        $this->allocationWriter->synchronize($invoice);

        return $payment;
    }

    private function confirmedPositiveTotalMinor(Invoice $invoice): int
    {
        $total = Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', 'confirmed')
            ->where('amount', '>', 0)
            ->sum('amount');

        return $this->money->toMinorUnits($total);
    }

    private function topUpCreditBalance(
        Invoice $invoice,
        Payment $payment,
        int $amountMinor
    ): void {
        $creditBalance = CreditBalance::query()->firstOrCreate(
            [
                'company_id' => $invoice->company_id,
                'organization_id' => $invoice->issuer_organization_id,
            ],
            ['amount' => '0.00']
        );

        $lockedBalance = CreditBalance::query()
            ->whereKey($creditBalance->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $lockedBalance->company_id !== (int) $invoice->company_id
            || ($lockedBalance->organization_id !== null
                && (int) $lockedBalance->organization_id !== (int) $invoice->issuer_organization_id)) {
            throw new LogicException('Credit Balance and Invoice companies do not match.');
        }

        $existingEntry = CreditBalanceEntry::query()
            ->where('type', 'top_up')
            ->where('payment_id', $payment->getKey())
            ->first();

        if ($existingEntry !== null) {
            if ((int) $existingEntry->credit_balance_id !== (int) $lockedBalance->getKey()) {
                throw new LogicException('Payment top-up belongs to a different Credit Balance.');
            }

            return;
        }

        $balanceMinor = $this->money->toMinorUnits($lockedBalance->getRawOriginal('amount'));
        $newBalanceMinor = $balanceMinor + $amountMinor;

        if ($balanceMinor < 0 || $newBalanceMinor > self::MAX_AMOUNT_MINOR) {
            throw new LogicException('Credit Balance amount is outside the supported range.');
        }

        $lockedBalance->entries()->create([
            'type' => 'top_up',
            'amount' => $this->money->fromMinorUnits($amountMinor),
            'payment_id' => $payment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'description' => "Переплата по платежу #{$payment->getKey()}",
        ]);

        $lockedBalance->forceFill([
            'amount' => $this->money->fromMinorUnits($newBalanceMinor),
        ])->saveQuietly();
    }
}
