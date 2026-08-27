<?php

namespace App\Http\Controllers\Web;

use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Exceptions\Payments\PaymentConfirmationException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ConfirmPayment $confirmPayment,
        private readonly CreateConfirmedPayment $createConfirmedPayment,
        private readonly CreatePendingPayment $createPendingPayment,
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
            'payment_date.required' => __('payments.validation.date_required'),

            'payment_date.date' => __('payments.validation.date_date'),

            'amount.required' => __('payments.validation.amount_required'),

            'amount.numeric' => __('payments.validation.amount_numeric'),

            'amount.decimal' => __('payments.validation.amount_decimal'),

            'amount.min' => __('payments.validation.amount_min'),

            'payment_method.required' => __('payments.validation.method_required'),

            'payment_method.in' => __('payments.validation.method_in'),

            'status.required' => __('payments.validation.status_required'),

            'status.in' => __('payments.validation.status_in'),

            'comment.max' => __('payments.validation.comment_max'),
        ]);

        if ($validated['status'] === 'confirmed') {
            $this->createConfirmedPayment->execute($invoice, $validated, $request->user());
        } else {
            try {
                $this->createPendingPayment->execute($invoice, [
                    'payment_date' => $validated['payment_date'],
                    'amount' => $validated['amount'],
                    'payment_method' => $validated['payment_method'],
                    'comment' => $validated['comment'] ?? null,
                ], $request->user());
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
                if (isset($errors['payment']) && ! isset($errors['amount'])) {
                    $errors['amount'] = $errors['payment'];
                    unset($errors['payment']);
                }

                throw ValidationException::withMessages($errors);
            }
        }

        return $this->mutationRedirect($invoice)
            ->with(
                'success',
                __('payments.flash.created')
            );
    }

    /**
     * Подтверждение ожидающего платежа.
     *
     * Финансовый lifecycle выполняется явно в ConfirmPayment.
     */
    public function confirm(
        Request $request,
        Payment $payment
    ): RedirectResponse {
        Gate::authorize('confirm', $payment);

        try {
            $confirmedPayment = $this->confirmPayment->execute($payment, $request->user());
        } catch (PaymentConfirmationException $exception) {
            throw ValidationException::withMessages([
                'payment_confirm' => $exception->getMessage(),
            ]);
        }

        return $this->mutationRedirect($confirmedPayment->invoice_id)
            ->with(
                'success',
                __('payments.flash.confirmed')
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
            'cancel_payment_id.required' => __('payments.validation.id_required'),

            'cancel_payment_id.integer' => __('payments.validation.id_integer'),

            'cancel_payment_id.in' => __('payments.validation.id_in'),

            'cancel_reason.required' => __('payments.validation.reason_required'),

            'cancel_reason.min' => __('payments.validation.reason_min'),

            'cancel_reason.max' => __('payments.validation.reason_max'),
        ]);

        $cancelledPayment = $this->cancelPayment->execute(
            $payment,
            $validated['cancel_reason'],
            $request->user(),
        );

        return $this->mutationRedirect($cancelledPayment->invoice_id)
            ->with(
                'success',
                __('payments.flash.cancelled')
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
