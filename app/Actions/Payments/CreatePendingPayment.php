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

final class CreatePendingPayment
{
    public function __construct(
        private readonly InvoicePaymentAvailabilityService $availabilityService,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array{payment_date: string, amount: string, payment_method: string, comment?: string|null} $attributes */
    public function execute(Invoice $invoice, array $attributes, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($invoice, $attributes, $actor): Payment {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages([
                    'payment' => 'Платёж можно добавить только по выставленному или частично оплаченному инвойсу.',
                ]);
            }

            $availability = $this->availabilityService->evaluatePendingCreation($lockedInvoice);
            $amountMinor = $this->availabilityService->toMinorUnits($attributes['amount']);

            if ($amountMinor < 1) {
                throw ValidationException::withMessages([
                    'payment' => 'Сумма платежа должна быть не меньше 0,01 ₼.',
                ]);
            }

            if ($amountMinor > $availability['available_minor']) {
                throw ValidationException::withMessages([
                    'payment' => 'Сумма платежа не может превышать доступный остаток '
                        .$this->availabilityService->formatMinorUnits($availability['available_minor']).'.',
                ]);
            }

            $payment = Payment::query()->create([
                'invoice_id' => $lockedInvoice->getKey(),
                'company_id' => $lockedInvoice->company_id,
                'payment_date' => $attributes['payment_date'],
                'amount' => $this->availabilityService->fromMinorUnits($amountMinor),
                'payment_method' => $attributes['payment_method'],
                'status' => 'pending',
                'comment' => $attributes['comment'] ?? null,
            ]);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForInvoice($lockedInvoice),
                CompanyActivityEventType::PaymentPendingCreated,
                CompanyActivityCategory::Payments,
                CompanyActivityVisibilityScope::Financials,
                subject: $lockedInvoice,
                metadata: CompanyActivitySnapshot::payment($payment, $lockedInvoice),
                actor: $actor,
            );

            return $payment;
        });
    }
}
