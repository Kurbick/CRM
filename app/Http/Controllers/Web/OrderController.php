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
        return view('orders.create', compact('contract'));
    }

    public function store(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'service_name'  => 'required|string|max:255',
            'order_date'    => 'required|date',
            'deadline'      => 'nullable|date|after:order_date',
            'price'         => 'required|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:1|max:365',
            'status'        => 'required|in:in_progress,completed,cancelled',
            'comment'       => 'nullable|string',
        ]);

        $serviceType = ServiceType::firstOrCreate(
            [
                'name' => trim($validated['service_name']),
                'type' => 'one_time',
            ],
            [
                'base_price' => $validated['price'],
            ]
        );

        unset($validated['service_name']);

        $validated['service_type_id'] = $serviceType->id;

        $contract->orders()->create($validated);

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Разовая услуга успешно добавлена.');
    }

    public function edit(Order $order)
    {
        $contract = $order->contract;

        return view('orders.edit', compact('order', 'contract'));
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'order_date'    => 'required|date',
            'deadline'      => 'nullable|date|after:order_date',
            'price'         => 'required|numeric|min:0',
            'payment_terms' => 'nullable|integer|min:1|max:365',
            'status'        => 'required|in:in_progress,completed,cancelled',
            'comment'       => 'nullable|string',
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['service_type_id'] = null;

        $order->update($validated);

        return redirect()
            ->route('contracts.show', $order->contract)
            ->with('success', 'Разовая услуга обновлена.');
    }

    public function destroy(Order $order)
    {
        $contract = $order->contract;

        try {
            $order->delete();

            return redirect()
                ->route('contracts.show', $contract)
                ->with('success', 'Разовая услуга удалена.');
        } catch (\Exception $e) {
            return redirect()
                ->route('contracts.show', $contract)
                ->with('error', 'Невозможно удалить — услуга включена в инвойс.');
        }
    }
}