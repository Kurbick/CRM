<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\DeleteContract;
use App\Actions\Contracts\UpdateContract;
use App\Exceptions\ContractDeletionException;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractController extends Controller
{
    private const COMPACT_FIELDS = [
        'id',
        'company_id',
        'contract_number',
        'start_date',
        'end_date',
        'status',
        'created_at',
        'updated_at',
    ];

    public function index(Company $company): JsonResponse
    {
        Gate::authorize('viewAny', Contract::class);

        $contracts = $company->contracts()
            ->select(self::COMPACT_FIELDS)
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract): array => $this->compactProjection($contract));

        return response()->json($contracts);
    }

    public function store(StoreContractRequest $request, Company $company, CreateContract $createContract): JsonResponse
    {
        $contract = $createContract->handle($company, $request->validated(), $request->user());

        return response()->json($this->detailProjection($contract, $company), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);

        return response()->json($this->detailProjection(
            $contract,
            $this->companySummaryModel($contract)
        ));
    }

    public function update(UpdateContractRequest $request, Contract $contract, UpdateContract $updateContract): JsonResponse
    {
        $contract = $updateContract->handle($contract, $request->validated(), $request->user());

        return response()->json($this->detailProjection(
            $contract,
            $this->companySummaryModel($contract)
        ));
    }

    public function destroy(Request $request, Contract $contract, DeleteContract $deleteContract): JsonResponse
    {
        Gate::authorize('delete', $contract);

        try {
            $deleteContract->handle($contract, $request->user());

            return response()->json(['message' => 'Контракт удалён'], 200);
        } catch (ContractDeletionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }
    }

    /** @return array<string, mixed> */
    private function compactProjection(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'company_id' => $contract->company_id,
            'contract_number' => $contract->contract_number,
            'start_date' => $contract->start_date?->toJSON(),
            'end_date' => $contract->end_date?->toJSON(),
            'status' => $contract->status,
            'created_at' => $contract->created_at?->toJSON(),
            'updated_at' => $contract->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailProjection(Contract $contract, Company $company): array
    {
        return [
            'id' => $contract->id,
            'company_id' => $contract->company_id,
            'contract_number' => $contract->contract_number,
            'start_date' => $contract->start_date?->toJSON(),
            'end_date' => $contract->end_date?->toJSON(),
            'status' => $contract->status,
            'comment' => $contract->comment,
            'created_at' => $contract->created_at?->toJSON(),
            'updated_at' => $contract->updated_at?->toJSON(),
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => $company->short_name,
            ],
        ];
    }

    private function companySummaryModel(Contract $contract): Company
    {
        return $contract->company()
            ->select(['companies.id', 'companies.name', 'companies.short_name'])
            ->firstOrFail();
    }
}
