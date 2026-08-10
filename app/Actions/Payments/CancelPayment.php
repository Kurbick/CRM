<?php

namespace App\Actions\Payments;

use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelPayment
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter
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

            if ($this->isCreditBalancePayment($lockedPayment)) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Автоматическое применение Credit Balance нельзя отменить как обычный платёж.',
                ]);
            }

            if ((int) $lockedPayment->company_id !== (int) $invoice->company_id) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Компания платежа не совпадает с компанией инвойса.',
                ]);
            }

            if ($lockedPayment->status === 'pending') {
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

            $this->reverseExcessCredit(
                invoice: $invoice,
                cancelledPayment: $lockedPayment,
                remainingConfirmedAmount: $remainingConfirmedAmount
            );

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

    private function isCreditBalancePayment(Payment $payment): bool
    {
        $hasAppliedCreditEntry = CreditBalanceEntry::query()
            ->where('type', 'applied')
            ->where('payment_id', $payment->getKey())
            ->where(function ($query) use ($payment): void {
                $query->where('invoice_id', $payment->invoice_id)
                    ->orWhereNull('invoice_id');
            })
            ->exists();

        return $hasAppliedCreditEntry || str_starts_with(
            (string) $payment->comment,
            'Автоматически применён Credit Balance'
        );
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
