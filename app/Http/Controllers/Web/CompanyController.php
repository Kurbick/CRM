<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Company::query();
        
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_name', 'like', "%{$search}%")
                  ->orWhere('voen', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        
        $companies = $query->orderBy('name')->paginate(10)->withQueryString();
        
        // Add calculated debt to each company on the current page
        $companies->getCollection()->transform(function ($company) {
            $invoiced = $company->invoices()->whereNotIn('status', ['cancelled'])->sum('total_amount');
            $paid = $company->payments()
                ->where('status', 'confirmed')
                ->where('comment', 'not like', '%Credit Balance%')
                ->sum('amount');
            $company->total_debt = max(0, $invoiced - $paid);
            return $company;
        });
        
        return view('companies.index', compact('companies'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('companies.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:company,individual',
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:100',
            'voen' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:50',
            'bank_code' => 'nullable|string|max:20',
            'bank_voen' => 'nullable|string|max:20',
            'swift' => 'nullable|string|max:20',
            'legal_address' => 'nullable|string|max:255',
            'actual_address' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'status' => 'required|in:active,suspended,archived',
            'invoice_mode' => 'required|in:separate,consolidated',
            'comment' => 'nullable|string',
        ]);

        $company = Company::create($validated);

        return redirect()->route('companies.show', $company)
            ->with('success', 'Компания успешно создана.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Company $company)
{
    $company->load([
        'contacts'     => fn($q) => $q->orderBy('first_name'),
        'contracts'    => fn($q) => $q->orderBy('start_date', 'desc'),
        'invoices'     => fn($q) => $q->orderBy('due_date', 'desc'),
        'payments'     => fn($q) => $q->orderBy('payment_date', 'desc'),
        'creditBalance',
    ]);

    $totalInvoiced = $company->invoices()->whereNotIn('status', ['cancelled'])->sum('total_amount');
    $totalPaid = $company->payments()
        ->where('status', 'confirmed')
        ->where('comment', 'not like', '%Credit Balance%')
        ->sum('amount');

    $stats = [
        'total_invoiced'  => $totalInvoiced,
        'total_paid'      => $totalPaid,
        'total_debt'      => max(0, $totalInvoiced - $totalPaid), // исправлено
        'credit_balance'  => $company->creditBalance?->amount ?? 0,
    ];

    return view('companies.show', compact('company', 'stats'));
}

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Company $company)
    {
        return view('companies.edit', compact('company'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'type' => 'required|in:company,individual',
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:100',
            'voen' => 'nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:50',
            'bank_code' => 'nullable|string|max:20',
            'bank_voen' => 'nullable|string|max:20',
            'swift' => 'nullable|string|max:20',
            'legal_address' => 'nullable|string|max:255',
            'actual_address' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'status' => 'required|in:active,suspended,archived',
            'invoice_mode' => 'required|in:separate,consolidated',
            'comment' => 'nullable|string',
        ]);

        $company->update($validated);

        return redirect()->route('companies.show', $company)
            ->with('success', 'Данные компании успешно обновлены.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Company $company)
    {
        if ($company->contracts()->exists() || $company->invoices()->exists()) {
            return redirect()->route('companies.show', $company)
                ->with('error', 'Невозможно удалить компанию, так как с ней связаны договоры или инвойсы.');
        }

        $company->delete();

        return redirect()->route('companies.index')
            ->with('success', 'Компания успешно удалена.');
    }
}
