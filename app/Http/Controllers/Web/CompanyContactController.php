<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\CompanyPageContext;
use Illuminate\Http\Request;

class CompanyContactController extends Controller
{
    public function create(Request $request, Company $company)
    {
        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');
        return view('contacts.create', compact('company', 'companyContext'));
    }

    public function store(Request $request, Company $company)
    {
        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'position'   => 'nullable|string|max:150',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:255',
            'role'       => 'nullable|in:director,accountant,manager,technical,other',
            'comment'    => 'nullable|string',
        ]);

        $company->contacts()->create($request->all());
        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');

        return redirect()->to($companyContext['active'] ? $companyContext['company_url'] : route('companies.show', $company))
            ->with('success', 'Контакт успешно добавлен.');
    }

    public function edit(Request $request, CompanyContact $contact)
    {
        $company = $contact->company;
        $companyContext = CompanyPageContext::resolve($request, $company, 'contacts');
        return view('contacts.edit', compact('contact', 'company', 'companyContext'));
    }

    public function update(Request $request, CompanyContact $contact)
    {
        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'position'   => 'nullable|string|max:150',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:255',
            'role'       => 'nullable|in:director,accountant,manager,technical,other',
            'comment'    => 'nullable|string',
        ]);

        $contact->update($request->all());
        $companyContext = CompanyPageContext::resolve($request, $contact->company, 'contacts');

        return redirect()->to($companyContext['active'] ? $companyContext['company_url'] : route('companies.show', $contact->company))
            ->with('success', 'Контакт обновлён.');
    }

    public function destroy(CompanyContact $contact)
    {
        $company = $contact->company;
        $contact->delete();

        return redirect()->route('companies.show', $company)
            ->with('success', 'Контакт удалён.');
    }
}
