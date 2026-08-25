<?php

namespace App\Actions\Payments;

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
use Illuminate\Validation\ValidationException;

final class CreateConfirmedPayment
{
    private const MAX_AMOUNT_MINOR = 9_999_999_999;

    public function __construct(
        private readonly InvoicePaymentAvailabilityService $availabilityService,
        private readonly ApplyConfirmedPaymentLifecycle $lifecycle,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array{payment_date: string, amount: mixed, payment_method: string, comment?: string|null} $attributes */
    public function execute(Invoice $invoice, array $attributes, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($invoice, $attributes, $actor): Payment {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages([
                    'amount' => 'Платёж можно добавить только по выставленному или частично оплаченному инвойсу.',
                ]);
            }

            $availability = $this->availabilityService->evaluatePendingCreation($lockedInvoice);
            $amountMinor = $this->availabilityService->toMinorUnits($attributes['amount']);

            if ($amountMinor < 1 || $amountMinor > self::MAX_AMOUNT_MINOR) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма платежа должна быть от 0,01 до 99 999 999,99 ₼.',
                ]);
            }

            if ($availability['pending_minor'] > 0 && $amountMinor > $availability['available_minor']) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма платежа не может превышать остаток '
                        .$this->availabilityService->formatMinorUnits($availability['available_minor']).'.',
                ]);
            }

            $payment = Payment::withoutEvents(fn (): Payment => Payment::query()->create([
                'company_id' => $lockedInvoice->company_id,
                'invoice_id' => $lockedInvoice->getKey(),
                'payment_date' => $attributes['payment_date'],
                'amount' => $this->availabilityService->fromMinorUnits($amountMinor),
                'payment_method' => $attributes['payment_method'],
                'status' => 'confirmed',
                'comment' => $attributes['comment'] ?? null,
            ]));

            $confirmedPayment = $this->lifecycle->execute($lockedInvoice, $payment);

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
