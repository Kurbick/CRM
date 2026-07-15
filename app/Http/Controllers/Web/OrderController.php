<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Order;
use App\Models\ServiceType;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function create(Contract $contract)
    {
        $serviceTypes = ServiceType::where('type', 'one_time')->orderBy('name')->get();
        return view('orders.create', compact('contract', 'serviceTypes'));
    }

    public function store(Request $request, Contract $contract)
    {
        $request->validate([
            'service_type_id' => 'required|exists:service_types,id',
            'order_date'      => 'required|date',
            'deadline'        => 'nullable|date|after:order_date',
            'price'           => 'required|numeric|min:0',
            'payment_terms'   => 'nullable|integer|min:1|max:365',
            'status'          => 'required|in:in_progress,completed,cancelled',
            'comment'         => 'nullable|string',
        ]);

        $contract->orders()->create($request->all());

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Заказ успешно добавлен.');
    }

    public function edit(Order $order)
    {
        $contract = $order->contract;
        $serviceTypes = ServiceType::where('type', 'one_time')->orderBy('name')->get();
        return view('orders.edit', compact('order', 'contract', 'serviceTypes'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'service_type_id' => 'required|exists:service_types,id',
            'order_date'      => 'required|date',
            'deadline'        => 'nullable|date|after:order_date',
            'price'           => 'required|numeric|min:0',
            'payment_terms'   => 'nullable|integer|min:1|max:365',
            'status'          => 'required|in:in_progress,completed,cancelled',
            'comment'         => 'nullable|string',
        ]);

        $order->update($request->all());

        return redirect()->route('contracts.show', $order->contract)
            ->with('success', 'Заказ обновлён.');
    }

    public function destroy(Order $order)
    {
        $contract = $order->contract;
        try {
            $order->delete();
            return redirect()->route('contracts.show', $contract)
                ->with('success', 'Заказ удалён.');
        } catch (\Exception $e) {
            return redirect()->route('contracts.show', $contract)
                ->with('error', 'Невозможно удалить — заказ включён в инвойс.');
        }
    }
}