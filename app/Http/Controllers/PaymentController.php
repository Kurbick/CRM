<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(
        private readonly InvoicePaymentAllocationWriter $allocationWriter
    ) {
    }

    /**
     * Все платежи по конкретному инвойсу.
     */
    public function index(Invoice $invoice): JsonResponse
    {
        return response()->json(
            $invoice->payments()->get()
        );
    }

    /**
     * Создать платёж по инвойсу.
     * company_id берём из инвойса — не доверяем клиенту.
     * После сохранения модель Payment автоматически
     * обновит статус инвойса через booted().
     */
    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $payment = DB::transaction(function () use ($request, $invoice): Payment {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedInvoice->status, ['paid', 'cancelled'])) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Нельзя добавить платёж — инвойс уже закрыт или отменён',
                ], 422));
            }

            $payment = $lockedInvoice->payments()->create([
                ...$request->validated(),
                'company_id' => $lockedInvoice->company_id,
            ]);

            if ($payment->status === 'confirmed') {
                $this->allocationWriter->synchronize($lockedInvoice);
            }

            return $payment;
        });

        // Подгружаем инвойс с обновлённым статусом для ответа
        $invoice->refresh();
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json([
            'payment' => $payment,
            'invoice' => $invoice,
        ], 201);
    }

    /**
     * Показать один платёж.
     */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['invoice', 'company']);

        return response()->json($payment);
    }

    /**
     * Обновить платёж — например подтвердить pending.
     * После обновления статус инвойса пересчитается автоматически.
     */
    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        DB::transaction(function () use ($request, $payment): void {
            $invoice = Invoice::query()
                ->whereKey($payment->invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $wasConfirmed = $lockedPayment->status === 'confirmed';

            $lockedPayment->update($request->validated());

            if ($wasConfirmed || $lockedPayment->status === 'confirmed') {
                $this->allocationWriter->synchronize($invoice);
            }

            $payment->setRawAttributes($lockedPayment->getAttributes(), true);
        });

        $invoice = $payment->invoice->fresh();
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json([
            'payment' => $payment,
            'invoice' => $invoice,
        ]);
    }

    /**
     * Удалить платёж.
     * После удаления статус инвойса нужно пересчитать вручную —
     * booted() срабатывает только при сохранении, не при удалении.
     */
    public function destroy(Payment $payment): JsonResponse
    {
        $invoice = $payment->invoice;
        $payment->delete();

        // Пересчитываем статус инвойса вручную после удаления платежа
        $paidAmount = $invoice->payments()
            ->where('status', 'confirmed')
            ->sum('amount');

        if ($paidAmount <= 0) {
            $invoice->update(['status' => 'issued']);
        } elseif ($paidAmount < $invoice->total_amount) {
            $invoice->update(['status' => 'partially_paid']);
        }

        return response()->json(['message' => 'Платёж удалён'], 200);
    }
}
