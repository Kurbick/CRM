<?php

namespace App\Actions\Credits;

use App\Actions\Payments\ApplyConfirmedPaymentLifecycle;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class ApplyCreditToInvoice
{
    private const MAX_AMOUNT_MINOR = 9_999_999_999;

    public function __construct(
        private readonly InvoicePaymentAvailabilityService $money,
        private readonly ApplyConfirmedPaymentLifecycle $paymentLifecycle,
    ) {}

    public function execute(
        Invoice $invoice,
        ?int $requestedAmountMinor = null,
    ): AppliedCreditResult {
        $this->validateRequestedAmount($requestedAmountMinor);

        return DB::transaction(function () use ($invoice, $requestedAmountMinor): AppliedCreditResult {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages([
                    'credit' => 'Credit Balance можно применить только к выставленному или частично оплаченному инвойсу.',
                ]);
            }

            $invoiceTotalMinor = $this->money->toMinorUnits(
                $lockedInvoice->getRawOriginal('total_amount'),
            );

            if ($invoiceTotalMinor < 1 || $invoiceTotalMinor > self::MAX_AMOUNT_MINOR) {
                throw new LogicException('Invoice total is outside the supported Credit application range.');
            }

            $paymentTotals = Payment::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'confirmed' AND amount > 0 THEN amount ELSE 0 END), 0) AS confirmed_amount")
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' AND amount > 0 THEN amount ELSE 0 END), 0) AS pending_amount")
                ->toBase()
                ->first();

            $confirmedMinor = $this->money->toMinorUnits($paymentTotals?->confirmed_amount ?? '0.00');
            $pendingMinor = $this->money->toMinorUnits($paymentTotals?->pending_amount ?? '0.00');
            $settledRemainingMinor = max(
                $invoiceTotalMinor - $confirmedMinor,
                0,
            );
            $unreservedRemainingMinor = max(
                $settledRemainingMinor - $pendingMinor,
                0,
            );

            if ($unreservedRemainingMinor === 0) {
                return AppliedCreditResult::noOp(AppliedCreditResult::FULLY_RESERVED);
            }

            $creditBalance = CreditBalance::query()
                ->where('company_id', $lockedInvoice->company_id)
                ->lockForUpdate()
                ->first();

            if ($creditBalance === null) {
                return AppliedCreditResult::noOp(AppliedCreditResult::NO_CREDIT_BALANCE);
            }

            if ((int) $creditBalance->company_id !== (int) $lockedInvoice->company_id) {
                throw new LogicException('Credit Balance and Invoice companies do not match.');
            }

            $alreadyApplied = CreditBalanceEntry::query()
                ->where('credit_balance_id', $creditBalance->getKey())
                ->where('type', 'applied')
                ->where('invoice_id', $lockedInvoice->getKey())
                ->exists();

            if ($alreadyApplied) {
                return AppliedCreditResult::noOp(
                    AppliedCreditResult::DUPLICATE,
                    (int) $creditBalance->getKey(),
                );
            }

            $availableCreditMinor = $this->money->toMinorUnits(
                $creditBalance->getRawOriginal('amount'),
            );

            if ($availableCreditMinor < 0 || $availableCreditMinor > self::MAX_AMOUNT_MINOR) {
                throw new LogicException('Credit Balance amount is outside the supported range.');
            }

            if ($availableCreditMinor === 0) {
                return AppliedCreditResult::noOp(
                    AppliedCreditResult::ZERO_CREDIT,
                    (int) $creditBalance->getKey(),
                );
            }

            $requestedMinor = $requestedAmountMinor ?? $unreservedRemainingMinor;
            $appliedMinor = min(
                $requestedMinor,
                $availableCreditMinor,
                $unreservedRemainingMinor,
            );

            if ($appliedMinor === 0) {
                return AppliedCreditResult::noOp(
                    AppliedCreditResult::FULLY_RESERVED,
                    (int) $creditBalance->getKey(),
                );
            }

            $appliedAmount = $this->money->fromMinorUnits($appliedMinor);
            $remainingCreditMinor = $availableCreditMinor - $appliedMinor;
            $entry = $creditBalance->entries()->create([
                'type' => 'applied',
                'amount' => $appliedAmount,
                'invoice_id' => $lockedInvoice->getKey(),
                'description' => "Применён к инвойсу #{$lockedInvoice->invoice_number}",
            ]);

            $creditBalance->forceFill([
                'amount' => $this->money->fromMinorUnits($remainingCreditMinor),
            ])->saveQuietly();

            $payment = Payment::withoutEvents(fn (): Payment => Payment::query()->create([
                'company_id' => $lockedInvoice->company_id,
                'invoice_id' => $lockedInvoice->getKey(),
                'payment_date' => now()->toDateString(),
                'amount' => $appliedAmount,
                'payment_method' => 'transfer',
                'status' => 'confirmed',
                'comment' => "Автоматически применён Credit Balance ({$appliedAmount} ₼)",
            ]));

            $entry->forceFill(['payment_id' => $payment->getKey()])->saveQuietly();
            $this->paymentLifecycle->execute($lockedInvoice, $payment);

            return AppliedCreditResult::applied(
                appliedAmountMinor: $appliedMinor,
                paymentId: (int) $payment->getKey(),
                entryId: (int) $entry->getKey(),
                creditBalanceId: (int) $creditBalance->getKey(),
            );
        });
    }

    private function validateRequestedAmount(?int $requestedAmountMinor): void
    {
        if ($requestedAmountMinor !== null
            && ($requestedAmountMinor < 1 || $requestedAmountMinor > self::MAX_AMOUNT_MINOR)) {
            throw new InvalidArgumentException('Requested Credit amount is outside the supported range.');
        }
    }
}
