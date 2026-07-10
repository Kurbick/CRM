<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
        'payment_date'   => 'required|date',
        'amount'         => 'required|numeric|min:0.01',
        // Убрали max: — теперь можно платить больше долга
        'payment_method' => 'required|in:cash,card,transfer',
        'status'         => 'required|in:pending,confirmed',
        'comment'        => 'nullable|string',
    ]);

    // Нельзя платить по закрытому или отменённому инвойсу
    if (in_array($invoice->status, ['paid', 'cancelled'])) {
        return back()->with('error', 'Нельзя добавить платёж — инвойс уже закрыт или отменён.');
    }

    $validated['company_id'] = $invoice->company_id;
    $validated['invoice_id'] = $invoice->id;

    $payment = Payment::create($validated);

    // Если платёж подтверждён и сумма превышает остаток — переплата уходит в баланс
    // Эта логика уже обрабатывается в booted() модели Payment автоматически

    return redirect()->route('invoices.show', $invoice)
        ->with('success', 'Платёж успешно зарегистрирован.');
}
}
