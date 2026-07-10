<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contract;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use Illuminate\Http\JsonResponse;

class ContractController extends Controller
{
    
    public function index(Company $company): JsonResponse
    {
        $contracts = $company->contracts()
            ->with(['orders', 'subscriptions'])
            ->get();

        return response()->json($contracts);
    }

    
    public function store(StoreContractRequest $request, Company $company): JsonResponse
    {
        $contract = $company->contracts()->create($request->validated());

        return response()->json($contract, 201);
    }

    
    public function show(Contract $contract): JsonResponse
    {
        $contract->load([
            'company',
            'orders.serviceType',
            'subscriptions.serviceType',
        ]);

        return response()->json($contract);
    }

    
    public function update(UpdateContractRequest $request, Contract $contract): JsonResponse
    {
        $contract->update($request->validated());

        return response()->json($contract);
    }

    
    public function destroy(Contract $contract): JsonResponse
    {
        try {
            $contract->delete();
            return response()->json(['message' => 'Контракт удалён'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить контракт — есть связанные заказы или подписки'
            ], 409);
        }
    }
}