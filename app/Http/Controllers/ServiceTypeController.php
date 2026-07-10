<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use App\Http\Requests\StoreServiceTypeRequest;
use App\Http\Requests\UpdateServiceTypeRequest;
use Illuminate\Http\JsonResponse;

class ServiceTypeController extends Controller
{
    
    public function index(): JsonResponse
    {
        return response()->json(
            ServiceType::with('items')->get()
        );
    }

    public function store(StoreServiceTypeRequest $request): JsonResponse
    {
        $serviceType = ServiceType::create($request->validated());

        return response()->json($serviceType, 201);
    }

    public function show(ServiceType $serviceType): JsonResponse
    {
        $serviceType->load(['items', 'orders', 'subscriptions']);

        return response()->json($serviceType);
    }

    public function update(UpdateServiceTypeRequest $request, ServiceType $serviceType): JsonResponse
    {
        $serviceType->update($request->validated());

        return response()->json($serviceType);
    }

    public function destroy(ServiceType $serviceType): JsonResponse
    {
        try {
            $serviceType->delete();
            return response()->json(['message' => 'Тип услуги удалён'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить — используется в заказах или подписках'
            ], 409);
        }
    }
}