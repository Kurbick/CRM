<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\DeleteContract;
use App\Exceptions\ContractDeletionException;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Models\Company;
use App\Models\Contract;
use App\Support\Access\PermissionName;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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

    public function destroy(string $contract, DeleteContract $deleteContract): JsonResponse
    {
        Gate::authorize(PermissionName::ContractsDelete->value);

        $contract = Contract::query()->findOrFail($contract);

        try {
            $deleteContract->handle($contract);

            return response()->json(['message' => 'Контракт удалён'], 200);
        } catch (ContractDeletionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }
    }
}
