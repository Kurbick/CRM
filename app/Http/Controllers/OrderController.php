<?php

namespace App\Http\Controllers;

use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\DeleteOrder;
use App\Actions\Orders\UpdateOrder;
use App\Exceptions\OrderDeletionException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Contract;
use App\Models\Order;
use App\Models\ServiceType;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    private const COMPACT_FIELDS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'order_date',
        'deadline',
        'price',
        'payment_terms',
        'status',
        'created_at',
        'updated_at',
    ];

    public function index(Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);

        $orders = $contract->orders()
            ->select(self::COMPACT_FIELDS)
            ->with('serviceType:id,name,type')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order): array => $this->compactProjection(
                $order,
                $order->serviceType
            ));

        return response()->json($orders);
    }

    public function store(StoreOrderRequest $request, Contract $contract, CreateOrder $createOrder): JsonResponse
    {
        $order = $createOrder->handle($contract, $request->validated(), $request->user());
        $order->refresh();

        return response()->json($this->detailProjection(
            $order,
            $contract,
            $this->serviceTypeSummaryModel($order)
        ), 201);
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return response()->json($this->detailProjection(
            $order,
            $this->contractSummaryModel($order),
            $this->serviceTypeSummaryModel($order)
        ));
    }

    public function update(
        UpdateOrderRequest $request,
        Order $order,
        UpdateOrder $updateOrder
    ): JsonResponse {
        $order = $updateOrder->handle($order, $request->validated(), $request->user());

        return response()->json($this->detailProjection(
            $order,
            $this->contractSummaryModel($order),
            $this->serviceTypeSummaryModel($order)
        ));
    }

    public function destroy(Request $request, Order $order, DeleteOrder $deleteOrder): JsonResponse
    {
        Gate::authorize('delete', $order);

        try {
            $deleteOrder->handle($order, $request->user());

            return response()->json(['message' => 'Заказ удалён'], 200);
        } catch (OrderDeletionException) {
            return response()->json([
                'message' => 'Невозможно удалить — заказ уже включён в инвойс',
            ], 409);
        }
    }

    /** @return array<string, mixed> */
    private function compactProjection(Order $order, ?ServiceType $serviceType): array
    {
        return [
            'id' => $order->id,
            'contract_id' => $order->contract_id,
            'service_type_id' => $order->service_type_id,
            'title' => $order->title,
            'order_date' => $this->dateValue($order->order_date),
            'deadline' => $this->dateValue($order->deadline),
            'price' => $this->decimalValue($order->price),
            'payment_terms' => (int) $order->payment_terms,
            'status' => $order->status,
            'created_at' => $order->created_at?->toJSON(),
            'updated_at' => $order->updated_at?->toJSON(),
            'service_type' => $this->serviceTypeProjection($serviceType),
        ];
    }

    /** @return array<string, mixed> */
    private function detailProjection(
        Order $order,
        Contract $contract,
        ?ServiceType $serviceType
    ): array {
        return [
            'id' => $order->id,
            'contract_id' => $order->contract_id,
            'service_type_id' => $order->service_type_id,
            'title' => $order->title,
            'order_date' => $this->dateValue($order->order_date),
            'deadline' => $this->dateValue($order->deadline),
            'price' => $this->decimalValue($order->price),
            'payment_terms' => (int) $order->payment_terms,
            'status' => $order->status,
            'comment' => $order->comment,
            'created_at' => $order->created_at?->toJSON(),
            'updated_at' => $order->updated_at?->toJSON(),
            'contract' => [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ],
            'service_type' => $this->serviceTypeProjection($serviceType),
        ];
    }

    /** @return array{id: int, name: string, type: string}|null */
    private function serviceTypeProjection(?ServiceType $serviceType): ?array
    {
        if ($serviceType === null) {
            return null;
        }

        return [
            'id' => $serviceType->id,
            'name' => $serviceType->name,
            'type' => $serviceType->type,
        ];
    }

    private function contractSummaryModel(Order $order): Contract
    {
        return $order->contract()
            ->select(['contracts.id', 'contracts.company_id', 'contracts.contract_number'])
            ->firstOrFail();
    }

    private function serviceTypeSummaryModel(Order $order): ?ServiceType
    {
        if ($order->service_type_id === null) {
            return null;
        }

        return $order->serviceType()
            ->select(['service_types.id', 'service_types.name', 'service_types.type'])
            ->first();
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
    }

    private function decimalValue(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
