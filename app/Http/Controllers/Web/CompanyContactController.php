<?php

namespace App\Http\Controllers\Web;

use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\UpdateContact;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\CompanyPageContext;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyContactController extends Controller
{
    public function create(Request $request, Company $company)
    {
        Gate::authorize('create', [CompanyContact::class, $company]);

        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');
        $backUrl = $this->backUrl($company, $companyContext);

        return view('contacts.create', compact('company', 'companyContext', 'backUrl'));
    }

    public function store(Request $request, Company $company, CreateContact $createContact)
    {
        Gate::authorize('create', [CompanyContact::class, $company]);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'role' => 'nullable|in:director,accountant,manager,technical,other',
            'comment' => 'nullable|string',
        ]);

        $createContact->execute($company, $validated, $request->user());

        return $this->redirectAfterMutation(
            $request,
            $company,
            'Контакт успешно добавлен.'
        );
    }

    public function edit(Request $request, CompanyContact $contact)
    {
        Gate::authorize('update', $contact);

        $company = $contact->company;
        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');
        $backUrl = $this->backUrl($company, $companyContext);

        return view('contacts.edit', compact('contact', 'company', 'companyContext', 'backUrl'));
    }

    public function update(Request $request, CompanyContact $contact, UpdateContact $updateContact)
    {
        Gate::authorize('update', $contact);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'role' => 'nullable|in:director,accountant,manager,technical,other',
            'comment' => 'nullable|string',
        ]);

        $updatedContact = $updateContact->execute($contact, $validated, $request->user());

        return $this->redirectAfterMutation(
            $request,
            $updatedContact->company,
            'Контакт обновлён.'
        );
    }

    public function destroy(Request $request, CompanyContact $contact, DeleteContact $deleteContact)
    {
        Gate::authorize('delete', $contact);

        $company = $contact->company;
        $deleteContact->execute($contact, $request->user());

        return $this->redirectAfterMutation(
            $request,
            $company,
            'Контакт удалён.'
        );
    }

    private function redirectAfterMutation(
        Request $request,
        Company $company,
        string $message
    ) {
        if (! Gate::allows('view', $company)) {
            return redirect()->to($this->landingUrl())->with('success', $message);
        }

        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');

        return redirect()
            ->to($companyContext['active']
                ? $companyContext['company_url']
                : route('companies.show', $company))
            ->with('success', $message);
    }

    private function backUrl(Company $company, array $companyContext): string
    {
        if (Gate::allows('view', $company)) {
            return $companyContext['active']
                ? $companyContext['company_url']
                : route('companies.show', $company);
        }

        return $this->landingUrl();
    }

    private function landingUrl(): string
    {
        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }
}
