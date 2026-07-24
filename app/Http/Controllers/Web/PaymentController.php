<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAllocationWriter;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter,
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService
    ) {
    }

    /**
     * Регистрация нового платежа.
     */
    public function store(
        Request $request,
        Invoice $invoice
    ): RedirectResponse {
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
            'payment_date.required' =>
            'Укажите дату платежа.',

            'payment_date.date' =>
            'Укажите корректную дату платежа.',

            'amount.required' =>
            'Укажите сумму платежа.',

            'amount.numeric' =>
            'Сумма платежа должна быть числом.',

            'amount.decimal' =>
            'Сумма платежа должна содержать не более двух знаков после запятой.',

            'amount.min' =>
            'Сумма платежа должна быть больше нуля.',

            'payment_method.required' =>
            'Выберите способ оплаты.',

            'payment_method.in' =>
            'Выбран некорректный способ оплаты.',

            'status.required' =>
            'Выберите статус платежа.',

            'status.in' =>
            'Выбран некорректный статус платежа.',

            'comment.max' =>
            'Комментарий не должен превышать 2000 символов.',
        ]);

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
                !in_array(
                    $lockedInvoice->status,
                    ['issued', 'partially_paid'],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'amount' =>
                    'Платёж можно добавить только по выставленному или частично оплаченному инвойсу.',
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

            $payment = Payment::query()->create([
                'company_id' => $lockedInvoice->company_id,
                'invoice_id' => $lockedInvoice->id,
                'payment_date' => $validated['payment_date'],
                'amount' => $this->paymentAvailabilityService->fromMinorUnits($paymentAmountMinor),
                'payment_method' =>
                $validated['payment_method'],
                'status' => $validated['status'],
                'comment' => $validated['comment'] ?? null,
            ]);

            if ($payment->status === 'confirmed') {
                $this->allocationWriter->synchronize($lockedInvoice);
            }

            /*
             * Пересчёт статуса инвойса и создание переплаты
             * выполняются в booted() модели Payment.
             */
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with(
                'success',
                'Платёж успешно зарегистрирован.'
            );
    }

    /**
     * Подтверждение ожидающего платежа.
     *
     * После изменения статуса модель Payment автоматически:
     * 1. пересчитает статус инвойса;
     * 2. обновит оплаченную сумму;
     * 3. создаст переплату в Credit Balance при необходимости.
     */
    public function confirm(
        Payment $payment
    ): RedirectResponse {
        $invoiceId = $payment->invoice_id;

        DB::transaction(function () use (
            $payment,
            &$invoiceId
        ): void {
            /*
         * Блокируем связанный инвойс и проверяем,
         * что он допускает подтверждение платежа.
         *
         * Статус paid разрешён: подтверждение может
         * создать переплату и увеличить Credit Balance.
         */
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Единый порядок блокировок lifecycle:
             * Invoice, затем изменяемый Payment.
             */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoiceId = $lockedPayment->invoice_id;

            if ((int) $lockedPayment->invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages([
                    'payment_confirm' =>
                    'Платёж не принадлежит заблокированному инвойсу.',
                ]);
            }

            if ($lockedPayment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'payment_confirm' =>
                    'Подтвердить можно только платёж со статусом «Ожидает подтверждения».',
                ]);
            }

            if (
                !in_array(
                    $invoice->status,
                    ['issued', 'partially_paid', 'paid'],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'payment_confirm' =>
                    'Нельзя подтвердить платёж по черновику или отменённому инвойсу.',
                ]);
            }

            if (
                (int) $lockedPayment->company_id
                !== (int) $invoice->company_id
            ) {
                throw ValidationException::withMessages([
                    'payment_confirm' =>
                    'Компания платежа не совпадает с компанией инвойса.',
                ]);
            }

            /*
         * Используем обычный save(), чтобы сработал
         * saved-обработчик модели Payment.
         */
            $lockedPayment->forceFill([
                'status' => 'confirmed',
                'cancelled_at' => null,
                'cancel_reason' => null,
            ])->save();

            $this->allocationWriter->synchronize($invoice);
        });

        return redirect()
            ->route('invoices.show', $invoiceId)
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
            'cancel_payment_id.required' =>
            'Не удалось определить отменяемый платёж.',

            'cancel_payment_id.integer' =>
            'Указан некорректный платёж.',

            'cancel_payment_id.in' =>
            'Платёж в форме не совпадает с отменяемым платежом.',

            'cancel_reason.required' =>
            'Укажите причину отмены платежа.',

            'cancel_reason.min' =>
            'Причина отмены должна содержать минимум 3 символа.',

            'cancel_reason.max' =>
            'Причина отмены не должна превышать 1000 символов.',
        ]);

        $invoiceId = null;

        DB::transaction(function () use (
            $payment,
            $validated,
            &$invoiceId
        ): void {
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Единый порядок блокировок lifecycle:
             * Invoice, затем изменяемый Payment.
             */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedPayment->invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages([
                    'cancel_reason' =>
                    'Платёж не принадлежит заблокированному инвойсу.',
                ]);
            }

            if (!in_array($lockedPayment->status, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'cancel_reason' =>
                    'Отменить можно только ожидающий или подтверждённый платёж.',
                ]);
            }

            /*
             * Автоматическое применение Credit Balance
             * должно отменяться отдельной обратной операцией.
             */
            if ($this->isCreditBalancePayment($lockedPayment)) {
                throw ValidationException::withMessages([
                    'cancel_reason' =>
                    'Автоматическое применение Credit Balance нельзя отменить как обычный платёж.',
                ]);
            }

            $invoiceId = $invoice->id;

            if (
                (int) $lockedPayment->company_id
                !== (int) $invoice->company_id
            ) {
                throw ValidationException::withMessages([
                    'cancel_reason' =>
                    'Компания платежа не совпадает с компанией инвойса.',
                ]);
            }

            if ($lockedPayment->status === 'pending') {
                $lockedPayment->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancel_reason' => $validated['cancel_reason'],
                ])->saveQuietly();

                return;
            }

            if (
                !in_array(
                    $invoice->status,
                    ['issued', 'partially_paid', 'paid'],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'cancel_reason' =>
                    'Нельзя отменить платёж этого инвойса.',
                ]);
            }

            /*
             * Общая сумма подтверждённых платежей,
             * которая останется после отмены.
             */
            $remainingConfirmedAmount = round(
                (float) $invoice->payments()
                    ->where('status', 'confirmed')
                    ->where(
                        'id',
                        '!=',
                        $lockedPayment->id
                    )
                    ->sum('amount'),
                2
            );

            /*
             * Убираем из Credit Balance ту переплату,
             * которая после отмены больше не существует.
             */
            $this->reverseExcessCredit(
                invoice: $invoice,
                cancelledPayment: $lockedPayment,
                remainingConfirmedAmount: $remainingConfirmedAmount
            );

            /*
             * Сохраняем отменённый платёж в истории.
             *
             * saveQuietly() предотвращает повторный запуск
             * событий модели Payment при отмене.
             */
            $lockedPayment->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' =>
                $validated['cancel_reason'],
            ])->saveQuietly();

            /*
             * После отмены пересчитываем статус инвойса.
             */
            $invoice->forceFill([
                'status' => $this->resolveInvoiceStatus(
                    confirmedAmount: $remainingConfirmedAmount,
                    invoiceTotal: (float) $invoice->total_amount
                ),
            ])->save();

            $this->allocationWriter->synchronize($invoice);
        });

        return redirect()
            ->route('invoices.show', $invoiceId)
            ->with(
                'success',
                'Платёж отменён. Статус инвойса и Credit Balance пересчитаны.'
            );
    }

    /**
     * Проверяет, был ли платёж создан через Credit Balance.
     */
    private function isCreditBalancePayment(
        Payment $payment
    ): bool {
        $hasAppliedCreditEntry = $payment->relationLoaded('creditBalanceEntries')
            ? $payment->creditBalanceEntries->contains('type', 'applied')
            : CreditBalanceEntry::query()
                ->where('type', 'applied')
                ->where('payment_id', $payment->id)
                ->exists();

        $hasCreditBalanceComment = str_starts_with(
            (string) $payment->comment,
            'Автоматически применён Credit Balance'
        );

        return $hasAppliedCreditEntry
            || $hasCreditBalanceComment;
    }

    /**
     * Отменяет часть Credit Balance, которая перестала
     * быть переплатой после отмены платежа.
     */
    private function reverseExcessCredit(
        Invoice $invoice,
        Payment $cancelledPayment,
        float $remainingConfirmedAmount
    ): void {
        $invoiceTotal = round(
            (float) $invoice->total_amount,
            2
        );

        /*
         * Какая переплата должна остаться
         * после отмены платежа.
         */
        $requiredOverpayment = round(
            max(
                0,
                $remainingConfirmedAmount - $invoiceTotal
            ),
            2
        );

        $invoicePaymentIds = $invoice->payments()
            ->pluck('id');

        if ($invoicePaymentIds->isEmpty()) {
            return;
        }

        /*
         * Сколько переплаты ранее было начислено
         * по платежам этого инвойса.
         */
        $creditedAmount = round(
            (float) CreditBalanceEntry::query()
                ->whereIn(
                    'payment_id',
                    $invoicePaymentIds
                )
                ->where('type', 'top_up')
                ->sum('amount'),
            2
        );

        /*
         * Сколько начисленной переплаты уже было отменено.
         */
        $reversedAmount = round(
            (float) CreditBalanceEntry::query()
                ->whereIn(
                    'payment_id',
                    $invoicePaymentIds
                )
                ->where('type', 'top_up_reversal')
                ->sum('amount'),
            2
        );

        $currentInvoiceCredit = round(
            max(
                0,
                $creditedAmount - $reversedAmount
            ),
            2
        );

        /*
         * Разница, которую необходимо снять
         * с Credit Balance компании.
         */
        $creditToReverse = round(
            max(
                0,
                $currentInvoiceCredit
                    - $requiredOverpayment
            ),
            2
        );

        if ($creditToReverse <= 0) {
            return;
        }

        $creditBalance = CreditBalance::query()
            ->where('company_id', $invoice->company_id)
            ->lockForUpdate()
            ->first();

        if (!$creditBalance) {
            throw ValidationException::withMessages([
                'cancel_reason' =>
                'Не найден Credit Balance компании.',
            ]);
        }

        $availableCredit = round(
            (float) $creditBalance->amount,
            2
        );

        /*
         * Если доступного баланса недостаточно,
         * значит часть переплаты уже была использована.
         */
        if ($availableCredit < $creditToReverse) {
            throw ValidationException::withMessages([
                'cancel_reason' =>
                'Нельзя отменить платёж: часть переплаты уже использована для оплаты другого инвойса.',
            ]);
        }

        /*
         * Создаём обратную запись.
         * Старые записи не изменяем и не удаляем.
         */
        $creditBalance->entries()->create([
            'type' => 'top_up_reversal',
            'amount' => $creditToReverse,
            'payment_id' => $cancelledPayment->id,
            'invoice_id' => $invoice->id,
            'description' =>
            "Отмена переплаты по платежу #{$cancelledPayment->id}",
        ]);

        $creditBalance->forceFill([
            'amount' => round(
                $availableCredit - $creditToReverse,
                2
            ),
        ])->save();
    }

    /**
     * Определяет статус инвойса по сумме
     * подтверждённых платежей.
     */
    private function resolveInvoiceStatus(
        float $confirmedAmount,
        float $invoiceTotal
    ): string {
        $confirmedAmount = round(
            $confirmedAmount,
            2
        );

        $invoiceTotal = round(
            $invoiceTotal,
            2
        );

        if ($confirmedAmount >= $invoiceTotal) {
            return 'paid';
        }

        if ($confirmedAmount > 0) {
            return 'partially_paid';
        }

        return 'issued';
    }
}
