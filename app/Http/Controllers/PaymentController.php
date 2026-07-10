<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
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
        // Проверяем что инвойс не отменён и не оплачен полностью
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            return response()->json([
                'message' => 'Нельзя добавить платёж — инвойс уже закрыт или отменён'
            ], 422);
        }

        $payment = $invoice->payments()->create([
            ...$request->validated(),
            'company_id' => $invoice->company_id,
            // company_id берём из инвойса, а не из запроса
        ]);

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
        $payment->update($request->validated());

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