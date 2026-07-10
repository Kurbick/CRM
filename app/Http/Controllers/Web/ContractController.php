<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function create(Company $company)
    {
        return view('contracts.create', compact('company'));
    }

    public function store(Request $request, Company $company)
    {
        $request->validate([
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number',
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after:start_date',
            'status'          => 'required|in:active,expired,terminated',
            'comment'         => 'nullable|string',
        ]);

        $company->contracts()->create($request->all());

        return redirect()->route('companies.show', $company)
            ->with('success', 'Договор успешно добавлен.');
    }

    public function edit(Contract $contract)
    {
        $company = $contract->company;
        return view('contracts.edit', compact('contract', 'company'));
    }

    public function update(Request $request, Contract $contract)
    {
        $request->validate([
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number,' . $contract->id,
            'start_date'      => 'required|date',
            'end_date'        => 'nullable|date|after:start_date',
            'status'          => 'required|in:active,expired,terminated',
            'comment'         => 'nullable|string',
        ]);

        $contract->update($request->all());

        return redirect()->route('companies.show', $contract->company)
            ->with('success', 'Договор обновлён.');
    }

    public function destroy(Contract $contract)
    {
        $company = $contract->company;

        try {
            $contract->delete();
            return redirect()->route('companies.show', $company)
                ->with('success', 'Договор удалён.');
        } catch (\Exception $e) {
            return redirect()->route('companies.show', $company)
                ->with('error', 'Невозможно удалить — есть связанные заказы или подписки.');
        }
    }
}