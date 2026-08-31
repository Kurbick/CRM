<?php

namespace App\Actions\Payments;

use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class CancelPayment
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter,
        private readonly InvoicePaymentAvailabilityService $money,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function execute(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        $paymentId = (int) $payment->getKey();
        $invoiceId = (int) $payment->invoice_id;

        return DB::transaction(function () use ($invoiceId, $paymentId, $reason, $actor): Payment {
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

                $cancelledPayment = $this->markCancelled($lockedPayment, $reason);
            } else {
                if (! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                    throw ValidationException::withMessages([
                        'cancel_reason' => 'Нельзя отменить платёж этого инвойса.',
                    ]);
                }

                $remainingConfirmedAmount = $this->money->toMinorUnits(
                    $invoice->payments()
                        ->where('status', 'confirmed')
                        ->whereKeyNot($lockedPayment->getKey())
                        ->sum('amount')
                );

                if ($appliedEntry !== null) {
                    $this->reverseAppliedCredit($invoice, $lockedPayment, $appliedEntry);
                } else {
                    $this->reverseExactUnusedTopUp($invoice, $lockedPayment);
                }

                $cancelledPayment = $this->markCancelled($lockedPayment, $reason);

                $invoice->forceFill([
                    'status' => $this->resolveInvoiceStatus(
                        confirmedAmount: $remainingConfirmedAmount,
                        invoiceTotal: $this->money->toMinorUnits($invoice->getRawOriginal('total_amount'))
                    ),
                ])->save();

                $this->allocationWriter->synchronize($invoice);
            }

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForInvoice($invoice),
                CompanyActivityEventType::PaymentCancelled,
                CompanyActivityCategory::Payments,
                CompanyActivityVisibilityScope::Financials,
                subject: $invoice,
                metadata: CompanyActivitySnapshot::payment($cancelledPayment, $invoice),
                actor: $actor,
            );

            return $cancelledPayment;
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
            || (int) $payment->company_id !== (int) $invoice->company_id
            || ($creditBalance->organization_id !== null
                && (int) $creditBalance->organization_id !== (int) $invoice->issuer_organization_id)) {
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

    private function reverseExactUnusedTopUp(
        Invoice $invoice,
        Payment $cancelledPayment,
    ): void {
        $topUpEntry = $this->resolveExactTopUpEntry($invoice, $cancelledPayment);

        if ($topUpEntry === null) {
            return;
        }

        $creditBalance = CreditBalance::query()
            ->whereKey($topUpEntry->credit_balance_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $creditBalance->company_id !== (int) $invoice->company_id
            || (int) $cancelledPayment->company_id !== (int) $invoice->company_id
            || ($creditBalance->organization_id !== null
                && (int) $creditBalance->organization_id !== (int) $invoice->issuer_organization_id)) {
            throw new LogicException('Top-up reversal company ownership is inconsistent.');
        }

        $existingReversal = CreditBalanceEntry::query()
            ->where('type', 'top_up_reversal')
            ->where('credit_balance_id', $creditBalance->getKey())
            ->where('payment_id', $cancelledPayment->getKey())
            ->where('invoice_id', $invoice->getKey())
            ->lockForUpdate()
            ->first();

        if ($existingReversal !== null) {
            throw new LogicException('Payment top-up has already been reversed.');
        }

        $hasPossibleDownstreamConsumption = CreditBalanceEntry::query()
            ->where('credit_balance_id', $creditBalance->getKey())
            ->where('type', 'applied')
            ->where('id', '>', $topUpEntry->getKey())
            ->lockForUpdate()
            ->first() !== null;

        if ($hasPossibleDownstreamConsumption) {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Нельзя отменить платёж: после переплаты Credit Balance использовался, поэтому источник средств нельзя однозначно определить.',
            ]);
        }

        $topUpMinor = $this->money->toMinorUnits($topUpEntry->getRawOriginal('amount'));
        $paymentMinor = $this->money->toMinorUnits($cancelledPayment->getRawOriginal('amount'));
        $balanceMinor = $this->money->toMinorUnits($creditBalance->getRawOriginal('amount'));

        if ($topUpMinor < 1 || $topUpMinor > $paymentMinor) {
            throw new LogicException('Payment top-up amount is inconsistent with its source Payment.');
        }

        if ($balanceMinor < $topUpMinor) {
            throw new LogicException('Credit Balance cannot cover the exact top-up reversal.');
        }

        $creditBalance->entries()->create([
            'type' => 'top_up_reversal',
            'amount' => $this->money->fromMinorUnits($topUpMinor),
            'payment_id' => $cancelledPayment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'description' => "Отмена переплаты по платежу #{$cancelledPayment->getKey()}",
        ]);

        $creditBalance->forceFill([
            'amount' => $this->money->fromMinorUnits($balanceMinor - $topUpMinor),
        ])->saveQuietly();
    }

    private function resolveExactTopUpEntry(
        Invoice $invoice,
        Payment $payment,
    ): ?CreditBalanceEntry {
        $entriesForPayment = CreditBalanceEntry::query()
            ->where('type', 'top_up')
            ->where('payment_id', $payment->getKey())
            ->orderBy('id')
            ->limit(2)
            ->get();

        $exactEntries = $entriesForPayment->filter(
            fn (CreditBalanceEntry $entry): bool => (int) $entry->payment_id === (int) $payment->getKey()
                && (int) $entry->invoice_id === (int) $invoice->getKey()
        );
        $hasAmbiguousEntry = $entriesForPayment->count() !== $exactEntries->count()
            || CreditBalanceEntry::query()
                ->where('type', 'top_up')
                ->whereNull('payment_id')
                ->where('invoice_id', $invoice->getKey())
                ->exists();

        if ($exactEntries->count() > 1 || ($exactEntries->isNotEmpty() && $hasAmbiguousEntry)) {
            throw new LogicException('Payment has inconsistent top-up ledger ownership.');
        }

        if ($exactEntries->isEmpty() && $hasAmbiguousEntry) {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Нельзя безопасно отменить legacy переплату без точной ledger-связи.',
            ]);
        }

        return $exactEntries->first();
    }

    private function resolveInvoiceStatus(int $confirmedAmount, int $invoiceTotal): string
    {
        if ($confirmedAmount >= $invoiceTotal) {
            return 'paid';
        }

        if ($confirmedAmount > 0) {
            return 'partially_paid';
        }

        return 'issued';
    }
}
