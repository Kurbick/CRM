<?php

namespace App\Http\Controllers\Web;

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Exceptions\Payments\PaymentConfirmationException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAvailabilityService;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService,
        private readonly ConfirmPayment $confirmPayment,
        private readonly CreateConfirmedPayment $createConfirmedPayment,
        private readonly CancelPayment $cancelPayment
    ) {}

    /**
     * Регистрация нового платежа.
     */
    public function store(
        Request $request,
        Invoice $invoice
    ): RedirectResponse {
        Gate::authorize('create', [Payment::class, $invoice]);

        $validated = $request->validate([
            'payment_date' => [
                'required',
                'date',
            ],

            /*
             * Без ожидающих платежей максимального ограничения нет:
             * переплата по-прежнему зачисляется в Credit Balance.
             * При наличии pending верхняя граница проверяется под lock ниже.
             */
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
            ],

            'payment_method' => [
                'required',
                'in:cash,card,transfer',
            ],

            'status' => [
                'required',
                'in:pending,confirmed',
            ],

            'comment' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'payment_date.required' => 'Укажите дату платежа.',

            'payment_date.date' => 'Укажите корректную дату платежа.',

            'amount.required' => 'Укажите сумму платежа.',

            'amount.numeric' => 'Сумма платежа должна быть числом.',

            'amount.decimal' => 'Сумма платежа должна содержать не более двух знаков после запятой.',

            'amount.min' => 'Сумма платежа должна быть больше нуля.',

            'payment_method.required' => 'Выберите способ оплаты.',

            'payment_method.in' => 'Выбран некорректный способ оплаты.',

            'status.required' => 'Выберите статус платежа.',

            'status.in' => 'Выбран некорректный статус платежа.',

            'comment.max' => 'Комментарий не должен превышать 2000 символов.',
        ]);

        if ($validated['status'] === 'confirmed') {
            $this->createConfirmedPayment->execute($invoice, $validated);
        } else {
            DB::transaction(function () use (
                $validated,
                $invoice
            ): void {
                /*
                 * Блокируем инвойс на время регистрации платежа,
                 * чтобы два одновременных платежа не нарушили
                 * расчёт статуса и переплаты.
                 */
                $lockedInvoice = Invoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Платежи разрешены только по выставленному
                 * или частично оплаченному инвойсу.
                 */
                if (
                    ! in_array(
                        $lockedInvoice->status,
                        ['issued', 'partially_paid'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'amount' => 'Платёж можно добавить только по выставленному или частично оплаченному инвойсу.',
                    ]);
                }

                $paymentAvailability = $this->paymentAvailabilityService->evaluate($lockedInvoice);
                $paymentAmountMinor = $this->paymentAvailabilityService->toMinorUnits($validated['amount']);

                if (
                    $paymentAvailability['pending_minor'] > 0
                    && $paymentAmountMinor > $paymentAvailability['available_minor']
                ) {
                    throw ValidationException::withMessages([
                        'amount' => 'Сумма платежа не может превышать остаток '
                            .$this->paymentAvailabilityService->formatMinorUnits(
                                $paymentAvailability['available_minor']
                            ).'.',
                    ]);
                }

                Payment::query()->create([
                    'company_id' => $lockedInvoice->company_id,
                    'invoice_id' => $lockedInvoice->id,
                    'payment_date' => $validated['payment_date'],
                    'amount' => $this->paymentAvailabilityService->fromMinorUnits($paymentAmountMinor),
                    'payment_method' => $validated['payment_method'],
                    'status' => 'pending',
                    'comment' => $validated['comment'] ?? null,
                ]);
            });
        }

        return $this->mutationRedirect($invoice)
            ->with(
                'success',
                'Платёж успешно зарегистрирован.'
            );
    }

    /**
     * Подтверждение ожидающего платежа.
     *
     * Финансовый lifecycle выполняется явно в ConfirmPayment.
     */
    public function confirm(
        Payment $payment
    ): RedirectResponse {
        Gate::authorize('confirm', $payment);

        try {
            $confirmedPayment = $this->confirmPayment->execute($payment);
        } catch (PaymentConfirmationException $exception) {
            throw ValidationException::withMessages([
                'payment_confirm' => $exception->getMessage(),
            ]);
        }

        return $this->mutationRedirect($confirmedPayment->invoice_id)
            ->with(
                'success',
                'Платёж подтверждён. Сумма оплаты и статус инвойса пересчитаны.'
            );
    }

    /**
     * Отмена ожидающего или подтверждённого платежа.
     *
     * Платёж не удаляется из базы, а сохраняется
     * в истории со статусом cancelled.
     */
    public function cancel(
        Request $request,
        Payment $payment
    ): RedirectResponse {
        Gate::authorize('cancel', $payment);

        $validated = $request->validate([
            'cancel_payment_id' => [
                'required',
                'integer',
                Rule::in([$payment->getKey()]),
            ],
            'cancel_reason' => [
                'required',
                'string',
                'min:3',
                'max:1000',
            ],
        ], [
            'cancel_payment_id.required' => 'Не удалось определить отменяемый платёж.',

            'cancel_payment_id.integer' => 'Указан некорректный платёж.',

            'cancel_payment_id.in' => 'Платёж в форме не совпадает с отменяемым платежом.',

            'cancel_reason.required' => 'Укажите причину отмены платежа.',

            'cancel_reason.min' => 'Причина отмены должна содержать минимум 3 символа.',

            'cancel_reason.max' => 'Причина отмены не должна превышать 1000 символов.',
        ]);

        $cancelledPayment = $this->cancelPayment->execute(
            $payment,
            $validated['cancel_reason']
        );

        return $this->mutationRedirect($cancelledPayment->invoice_id)
            ->with(
                'success',
                'Платёж отменён. Статус инвойса и Credit Balance пересчитаны.'
            );
    }

    private function mutationRedirect(Invoice|int $invoice)
    {
        $invoice = $invoice instanceof Invoice
            ? $invoice
            : Invoice::query()->select(['id', 'company_id'])->findOrFail($invoice);

        if (Gate::allows('view', $invoice)) {
            return redirect()->route('invoices.show', $invoice);
        }

        $invoice->loadMissing('company:id,name');

        if (Gate::allows('view', $invoice->company)) {
            return redirect()->route('companies.show', $invoice->company);
        }

        return redirect()->to(app(AuthorizedLandingPage::class)->url(auth()->user()));
    }
}
