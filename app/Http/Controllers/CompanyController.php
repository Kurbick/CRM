<?php

namespace App\Http\Controllers;

use App\Actions\Companies\DeleteCompany;
use App\Exceptions\CompanyDeletionException;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    private const COMPACT_FIELDS = [
        'id',
        'type',
        'name',
        'short_name',
        'voen',
        'email',
        'phone',
        'website',
        'status',
        'created_at',
        'updated_at',
    ];

    private const DETAIL_FIELDS = [
        'bank_name',
        'iban',
        'bank_code',
        'bank_voen',
        'swift',
        'legal_address',
        'actual_address',
        'comment',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Company::class);

        $companies = Company::query()
            ->select(self::COMPACT_FIELDS)
            ->orderBy('id')
            ->paginate(
                ApiPagination::perPage($request),
                self::COMPACT_FIELDS,
                'page',
                ApiPagination::page($request),
            );

        return response()->json(ApiPagination::envelope(
            $companies,
            fn (Company $company): array => $this->compactProjection($company),
        ));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());
        $company->refresh();

        return response()->json($this->detailProjection($company), 201);
    }

    public function show(Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        return response()->json($this->detailProjection($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        return response()->json($this->detailProjection($company));
    }

    public function destroy(Company $company, DeleteCompany $deleteCompany): JsonResponse
    {
        Gate::authorize('delete', $company);

        try {
            $deleteCompany->handle($company);
        } catch (CompanyDeletionException) {
            return response()->json([
                'message' => 'Невозможно удалить компанию — есть связанные данные',
            ], 409);
        }

        return response()->json(['message' => 'Компания удалена'], 200);
    }

    /** @return array<string, mixed> */
    private function compactProjection(Company $company): array
    {
        return $company->only(self::COMPACT_FIELDS);
    }

    /** @return array<string, mixed> */
    private function detailProjection(Company $company): array
    {
        return $company->only([
            ...self::COMPACT_FIELDS,
            ...self::DETAIL_FIELDS,
        ]);
    }
}
