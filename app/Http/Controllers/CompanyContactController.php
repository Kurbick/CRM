<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Http\Requests\StoreCompanyContactRequest;
use App\Http\Requests\UpdateCompanyContactRequest;
use Illuminate\Http\JsonResponse;

class CompanyContactController extends Controller
{
    /**
     * Все контактные лица конкретной компании.
     */
    public function index(Company $company): JsonResponse
    {
        return response()->json(
            $company->contacts()->get()
        );
    }

    /**
     * Создать контактное лицо для компании.
     * company_id берём из URL — не из тела запроса.
     */
    public function store(StoreCompanyContactRequest $request, Company $company): JsonResponse
    {
        $contact = $company->contacts()->create($request->validated());

        return response()->json($contact, 201);
    }

    public function show(CompanyContact $companyContact): JsonResponse
    {
        $companyContact->load('company');

        return response()->json($companyContact);
    }

    public function update(UpdateCompanyContactRequest $request, CompanyContact $companyContact): JsonResponse
    {
        $companyContact->update($request->validated());

        return response()->json($companyContact);
    }

    public function destroy(CompanyContact $companyContact): JsonResponse
    {
        $companyContact->delete();

        return response()->json(['message' => 'Контакт удалён'], 200);
    }
}