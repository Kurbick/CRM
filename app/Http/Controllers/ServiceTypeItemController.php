<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use App\Models\ServiceTypeItem;
use App\Http\Requests\StoreServiceTypeItemRequest;
use App\Http\Requests\UpdateServiceTypeItemRequest;
use Illuminate\Http\JsonResponse;

class ServiceTypeItemController extends Controller
{
    /**
     * Все компоненты конкретного пакета услуг.
     */
    public function index(ServiceType $serviceType): JsonResponse
    {
        return response()->json(
            $serviceType->items()->get()
        );
    }

    /**
     * Добавить компонент в пакет услуг.
     */
    public function store(StoreServiceTypeItemRequest $request, ServiceType $serviceType): JsonResponse
    {
        $item = $serviceType->items()->create($request->validated());

        return response()->json($item, 201);
    }

    public function show(ServiceTypeItem $serviceTypeItem): JsonResponse
    {
        $serviceTypeItem->load('serviceType');

        return response()->json($serviceTypeItem);
    }

    public function update(UpdateServiceTypeItemRequest $request, ServiceTypeItem $serviceTypeItem): JsonResponse
    {
        $serviceTypeItem->update($request->validated());

        return response()->json($serviceTypeItem);
    }

    public function destroy(ServiceTypeItem $serviceTypeItem): JsonResponse
    {
        $serviceTypeItem->delete();

        return response()->json(['message' => 'Компонент удалён'], 200);
    }
}