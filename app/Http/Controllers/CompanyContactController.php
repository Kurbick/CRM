<?php

namespace App\Http\Controllers;

use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\UpdateContact;
use App\Http\Requests\StoreCompanyContactRequest;
use App\Http\Requests\UpdateCompanyContactRequest;
use App\Models\Company;
use App\Models\CompanyContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyContactController extends Controller
{
    private const CONTACT_FIELDS = [
        'id',
        'company_id',
        'first_name',
        'last_name',
        'position',
        'phone',
        'email',
        'role',
        'comment',
        'created_at',
        'updated_at',
    ];

    /**
     * Все контактные лица конкретной компании.
     */
    public function index(Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        return response()->json(
            $company->contacts()
                ->select(self::CONTACT_FIELDS)
                ->orderBy('id')
                ->get()
                ->map(fn (CompanyContact $contact): array => $this->contactProjection($contact))
        );
    }

    /**
     * Создать контактное лицо для компании.
     * company_id берём из URL — не из тела запроса.
     */
    public function store(
        StoreCompanyContactRequest $request,
        Company $company,
        CreateContact $createContact,
    ): JsonResponse {
        $contact = $createContact->execute($company, $request->validated(), $request->user());

        return response()->json($this->contactProjection($contact, $company), 201);
    }

    public function show(CompanyContact $contact): JsonResponse
    {
        Gate::authorize('view', $contact);

        return response()->json($this->contactProjection(
            $contact,
            $this->companySummaryModel($contact)
        ));
    }

    public function update(
        UpdateCompanyContactRequest $request,
        CompanyContact $contact,
        UpdateContact $updateContact,
    ): JsonResponse {
        $contact = $updateContact->execute($contact, $request->validated(), $request->user());

        return response()->json($this->contactProjection(
            $contact,
            $this->companySummaryModel($contact)
        ));
    }

    public function destroy(
        Request $request,
        CompanyContact $contact,
        DeleteContact $deleteContact,
    ): JsonResponse {
        Gate::authorize('delete', $contact);

        $deleteContact->execute($contact, $request->user());

        return response()->json(['message' => 'Контакт удалён'], 200);
    }

    /** @return array<string, mixed> */
    private function contactProjection(CompanyContact $contact, ?Company $company = null): array
    {
        $projection = $contact->only(self::CONTACT_FIELDS);

        if ($company !== null) {
            $projection['company'] = $company->only(['id', 'name', 'short_name']);
        }

        return $projection;
    }

    private function companySummaryModel(CompanyContact $contact): Company
    {
        return $contact->company()
            ->select(['companies.id', 'companies.name', 'companies.short_name'])
            ->firstOrFail();
    }
}
