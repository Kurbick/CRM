<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    
    public function index(): JsonResponse
    {
        $companies = Company::with(['contacts', 'contracts'])->get();

        return response()->json($companies);
    }

    
    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());

        return response()->json($company, 201);
    }

   
    public function show(Company $company): JsonResponse
    {
        $company->load([
            'contacts',
            'contracts.orders',
            'contracts.subscriptions',
            'invoices',
            'payments',
        ]);

        return response()->json($company);
    }

    
    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        return response()->json($company);
    }

    
    public function destroy(Company $company): JsonResponse
    {
        try {
            $company->delete(); // @phpstan-ignore-line
            return response()->json(['message' => 'Компания удалена'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Невозможно удалить компанию — есть связанные данные'
            ], 409); // 409 Conflict
        }
    }
}