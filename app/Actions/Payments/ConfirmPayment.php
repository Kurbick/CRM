<?php

namespace App\Actions\Payments;

use App\Exceptions\Payments\PaymentConfirmationException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ConfirmPayment
{
    private const MAX_AMOUNT_MINOR = 9_999_999_999;

    public function __construct(
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService,
        private readonly ApplyConfirmedPaymentLifecycle $lifecycle,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function execute(Payment $payment, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $actor): Payment {
            $lockedInvoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedPayment->invoice_id !== (int) $lockedInvoice->getKey()) {
                throw PaymentConfirmationException::invoiceMismatch();
            }

            if ($lockedPayment->status !== 'pending') {
                throw PaymentConfirmationException::paymentNotPending();
            }

            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid', 'paid'], true)) {
                throw PaymentConfirmationException::invoiceStateNotAllowed();
            }

            if ((int) $lockedPayment->company_id !== (int) $lockedInvoice->company_id) {
                throw PaymentConfirmationException::companyMismatch();
            }

            try {
                $amountMinor = $this->paymentAvailabilityService->toMinorUnits(
                    $lockedPayment->getRawOriginal('amount')
                );
            } catch (LogicException) {
                throw PaymentConfirmationException::invalidAmount();
            }

            if ($amountMinor < 1 || $amountMinor > self::MAX_AMOUNT_MINOR) {
                throw PaymentConfirmationException::invalidAmount();
            }

            $lockedPayment->forceFill([
                'status' => 'confirmed',
                'cancelled_at' => null,
                'cancel_reason' => null,
            ])->saveQuietly();

            $confirmedPayment = $this->lifecycle->execute($lockedInvoice, $lockedPayment);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForInvoice($lockedInvoice),
                CompanyActivityEventType::PaymentConfirmed,
                CompanyActivityCategory::Payments,
                CompanyActivityVisibilityScope::Financials,
                subject: $lockedInvoice,
                metadata: CompanyActivitySnapshot::payment($confirmedPayment, $lockedInvoice),
                actor: $actor,
            );

            return $confirmedPayment;
        });
    }
}
