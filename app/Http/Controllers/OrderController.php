<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Order;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    /**
     * Все заказы конкретного контракта.
     */
    public function index(Contract $contract): JsonResponse
    {
        $orders = $contract->orders()
            ->with('serviceType')
            ->get();

        return response()->json($orders);
    }

    /**
     * Создать заказ внутри контракта.
     * service_type_id приходит из тела запроса —
     * клиент выбирает какую услугу заказывает.
     */
    public function store(StoreOrderRequest $request, Contract $contract): JsonResponse
    {
        $order = $contract->orders()->create($request->validated());

        return response()->json($order->load('serviceType'), 201);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['contract.company', 'serviceType', 'invoiceLines']);

        return response()->json($order);
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $order->update($request->validated());

        return response()->json($order);
    }

    public function destroy(Order $order): JsonResponse
    {
        try {
            $order->delete();
            return response()->json(['message' => 'Заказ удалён'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить — заказ уже включён в инвойс'
            ], 409);
        }
    }
}