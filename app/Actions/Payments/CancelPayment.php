<?php

namespace App\Actions\Payments;

use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class CancelPayment
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter,
        private readonly InvoicePaymentAvailabilityService $money,
    ) {}

    public function execute(Payment $payment, string $reason): Payment
    {
        $paymentId = (int) $payment->getKey();
        $invoiceId = (int) $payment->invoice_id;

        return DB::transaction(function () use ($invoiceId, $paymentId, $reason): Payment {
            $invoice = Invoice::query()
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedPayment->invoice_id !== (int) $invoice->getKey()) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Платёж не принадлежит заблокированному инвойсу.',
                ]);
            }

            if (! in_array($lockedPayment->status, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Отменить можно только ожидающий или подтверждённый платёж.',
                ]);
            }

            if ((int) $lockedPayment->company_id !== (int) $invoice->company_id) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Компания платежа не совпадает с компанией инвойса.',
                ]);
            }

            $appliedEntry = $this->resolveExactAppliedEntry($lockedPayment);

            if ($appliedEntry === null && $this->hasAmbiguousCreditSource($lockedPayment)) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Нельзя безопасно отменить legacy Credit Balance платёж без точной ledger-связи.',
                ]);
            }

            if ($lockedPayment->status === 'pending') {
                if ($appliedEntry !== null) {
                    throw new LogicException('Credit-funded Payment must be confirmed before cancellation.');
                }

                return $this->markCancelled($lockedPayment, $reason);
            }

            if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Нельзя отменить платёж этого инвойса.',
                ]);
            }

            $remainingConfirmedAmount = round(
                (float) $invoice->payments()
                    ->where('status', 'confirmed')
                    ->whereKeyNot($lockedPayment->getKey())
                    ->sum('amount'),
                2
            );

            if ($appliedEntry !== null) {
                $this->reverseAppliedCredit($invoice, $lockedPayment, $appliedEntry);
            } else {
                $this->reverseExcessCredit(
                    invoice: $invoice,
                    cancelledPayment: $lockedPayment,
                    remainingConfirmedAmount: $remainingConfirmedAmount
                );
            }

            $this->markCancelled($lockedPayment, $reason);

            $invoice->forceFill([
                'status' => $this->resolveInvoiceStatus(
                    confirmedAmount: $remainingConfirmedAmount,
                    invoiceTotal: (float) $invoice->total_amount
                ),
            ])->save();

            $this->allocationWriter->synchronize($invoice);

            return $lockedPayment;
        });
    }

    private function markCancelled(Payment $payment, string $reason): Payment
    {
        $payment->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->saveQuietly();

        return $payment;
    }

    private function resolveExactAppliedEntry(Payment $payment): ?CreditBalanceEntry
    {
        $entries = CreditBalanceEntry::query()
            ->where('type', 'applied')
            ->where('payment_id', $payment->getKey())
            ->where('invoice_id', $payment->invoice_id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($entries->count() > 1) {
            throw new LogicException('Credit-funded Payment has multiple exact applied ledger entries.');
        }

        return $entries->first();
    }

    private function hasAmbiguousCreditSource(Payment $payment): bool
    {
        $hasLegacyAppliedEntry = CreditBalanceEntry::query()
            ->where('type', 'applied')
            ->where('payment_id', $payment->getKey())
            ->whereNull('invoice_id')
            ->exists();

        return $hasLegacyAppliedEntry || str_starts_with(
            (string) $payment->comment,
            'Автоматически применён Credit Balance'
        );
    }

    private function reverseAppliedCredit(
        Invoice $invoice,
        Payment $payment,
        CreditBalanceEntry $appliedEntry,
    ): void {
        if ((int) $appliedEntry->invoice_id !== (int) $invoice->getKey()
            || (int) $appliedEntry->payment_id !== (int) $payment->getKey()) {
            throw new LogicException('Applied Credit entry does not belong to the cancelled Payment and Invoice.');
        }

        $creditBalance = CreditBalance::query()
            ->whereKey($appliedEntry->credit_balance_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $creditBalance->company_id !== (int) $invoice->company_id
            || (int) $payment->company_id !== (int) $invoice->company_id) {
            throw new LogicException('Credit reversal company ownership is inconsistent.');
        }

        $existingReversal = CreditBalanceEntry::query()
            ->where('type', 'applied_reversal')
            ->where('credit_balance_id', $creditBalance->getKey())
            ->where('payment_id', $payment->getKey())
            ->where('invoice_id', $invoice->getKey())
            ->lockForUpdate()
            ->first();

        if ($existingReversal !== null) {
            throw new LogicException('Credit-funded Payment has already been reversed.');
        }

        $appliedMinor = $this->money->toMinorUnits($appliedEntry->getRawOriginal('amount'));
        $paymentMinor = $this->money->toMinorUnits($payment->getRawOriginal('amount'));
        $balanceMinor = $this->money->toMinorUnits($creditBalance->getRawOriginal('amount'));

        if ($appliedMinor < 1 || $appliedMinor !== $paymentMinor) {
            throw new LogicException('Applied Credit amount does not match the Credit-funded Payment.');
        }

        $restoredBalanceMinor = $balanceMinor + $appliedMinor;
        if ($balanceMinor < 0 || $restoredBalanceMinor > 9_999_999_999) {
            throw new LogicException('Credit Balance amount is outside the supported reversal range.');
        }

        $creditBalance->entries()->create([
            'type' => 'applied_reversal',
            'amount' => $this->money->fromMinorUnits($appliedMinor),
            'payment_id' => $payment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'description' => "Отмена применения Credit Balance по платежу #{$payment->getKey()}",
        ]);

        $creditBalance->forceFill([
            'amount' => $this->money->fromMinorUnits($restoredBalanceMinor),
        ])->saveQuietly();
    }

    private function reverseExcessCredit(
        Invoice $invoice,
        Payment $cancelledPayment,
        float $remainingConfirmedAmount
    ): void {
        $invoiceTotal = round((float) $invoice->total_amount, 2);
        $requiredOverpayment = round(max(0, $remainingConfirmedAmount - $invoiceTotal), 2);
        $invoicePaymentIds = $invoice->payments()->pluck('id');

        if ($invoicePaymentIds->isEmpty()) {
            return;
        }

        $creditedAmount = round(
            (float) CreditBalanceEntry::query()
                ->whereIn('payment_id', $invoicePaymentIds)
                ->where('type', 'top_up')
                ->sum('amount'),
            2
        );
        $reversedAmount = round(
            (float) CreditBalanceEntry::query()
                ->whereIn('payment_id', $invoicePaymentIds)
                ->where('type', 'top_up_reversal')
                ->sum('amount'),
            2
        );
        $currentInvoiceCredit = round(max(0, $creditedAmount - $reversedAmount), 2);
        $creditToReverse = round(max(0, $currentInvoiceCredit - $requiredOverpayment), 2);

        if ($creditToReverse <= 0) {
            return;
        }

        $creditBalance = CreditBalance::query()
            ->where('company_id', $invoice->company_id)
            ->lockForUpdate()
            ->first();

        if (! $creditBalance) {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Не найден Credit Balance компании.',
            ]);
        }

        $availableCredit = round((float) $creditBalance->amount, 2);

        if ($availableCredit < $creditToReverse) {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Нельзя отменить платёж: часть переплаты уже использована для оплаты другого инвойса.',
            ]);
        }

        $creditBalance->entries()->create([
            'type' => 'top_up_reversal',
            'amount' => $creditToReverse,
            'payment_id' => $cancelledPayment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'description' => "Отмена переплаты по платежу #{$cancelledPayment->getKey()}",
        ]);

        $creditBalance->forceFill([
            'amount' => round($availableCredit - $creditToReverse, 2),
        ])->save();
    }

    private function resolveInvoiceStatus(float $confirmedAmount, float $invoiceTotal): string
    {
        $confirmedAmount = round($confirmedAmount, 2);
        $invoiceTotal = round($invoiceTotal, 2);

        if ($confirmedAmount >= $invoiceTotal) {
            return 'paid';
        }

        if ($confirmedAmount > 0) {
            return 'partially_paid';
        }

        return 'issued';
    }
}
