<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Contract;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Invoice::query()->with('company');
        
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('payer_name', 'like', "%{$search}%");
            });
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }
        
        if ($request->boolean('overdue')) {
            $query->whereNotIn('status', ['paid', 'cancelled'])
                  ->where('due_date', '<', now()->toDateString());
        }
        
        $invoices = $query->orderBy('due_date', 'desc')->paginate(10)->withQueryString();
        $companies = Company::orderBy('name')->get();
        
        return view('invoices.index', compact('invoices', 'companies'));
    }

    /**
     * Show the form for creating a new resource.
     */
        public function create()
    {
        $companies = \App\Models\Company::where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('invoices.create', compact('companies'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id'          => 'required|exists:companies,id',
            'invoice_number'      => 'required|string|max:50|unique:invoices,invoice_number',
            'issue_date'          => 'required|date',
            'due_date'            => 'required|date|after_or_equal:issue_date',
            'period_start'        => 'nullable|date',
            'period_end'          => 'nullable|date|after_or_equal:period_start',
            'status'              => 'required|in:draft,issued,partially_paid,paid,cancelled',
            'seller_name'         => 'nullable|string|max:255',
            'seller_voen'         => 'nullable|string|max:20',
            'seller_bank_name'    => 'nullable|string|max:255',
            'seller_iban'         => 'nullable|string|max:50',
            'seller_bank_code'    => 'nullable|string|max:20',
            'seller_bank_voen'    => 'nullable|string|max:20',
            'seller_swift'        => 'nullable|string|max:20',
            'payer_name'          => 'nullable|string|max:255',
            'payer_voen'          => 'nullable|string|max:20',
            'contract_reference'  => 'nullable|string|max:50',
            'comment'             => 'nullable|string',
            'lines'               => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.amount'      => 'required|numeric|min:0.01',
            'lines.*.subscription_id' => 'nullable|exists:subscriptions,id',
            'lines.*.order_id'    => 'nullable|exists:orders,id',
        ]);

        $invoice = DB::transaction(function () use ($request, $validated) {
            $lines = $request->input('lines');
            $totalAmount = collect($lines)->sum('amount');

            $company = \App\Models\Company::find($validated['company_id']);

            $invoiceData = collect($validated)->except('lines')->toArray();
            $invoiceData['total_amount'] = $totalAmount;

            // Автоподстановка реквизитов плательщика если не заполнено
            if (empty($invoiceData['payer_name'])) {
                $invoiceData['payer_name'] = $company->name;
            }
            if (empty($invoiceData['payer_voen'])) {
                $invoiceData['payer_voen'] = $company->voen;
            }

            $invoice = \App\Models\Invoice::create($invoiceData);

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description'     => $line['description'],
                    'amount'          => $line['amount'],
                    'subscription_id' => $line['subscription_id'] ?? null,
                    'order_id'        => $line['order_id'] ?? null,
                ]);
            }

            // Применяем Credit Balance если есть
            $creditBalance = $company->creditBalance;
            if ($creditBalance && $creditBalance->amount > 0) {
                $applied = $creditBalance->apply($invoice->total_amount, $invoice);
                if ($applied > 0) {
                    $invoice->payments()->create([
                        'company_id'     => $company->id,
                        'payment_date'   => now()->toDateString(),
                        'amount'         => $applied,
                        'payment_method' => 'transfer',
                        'status'         => 'confirmed',
                        'comment'        => "Автоматически применён Credit Balance ({$applied} ₼)",
                    ]);
                }
            }

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Инвойс успешно выставлен.');
    }
    
    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['company', 'lines', 'payments' => fn($q) => $q->orderBy('payment_date', 'desc')]);
        
        return view('invoices.show', compact('invoice'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $invoice)
    {
        $invoice->load('lines');
        $companies = Company::where('status', '!=', 'archived')->orderBy('name')->get();
        
        return view('invoices.edit', compact('invoice', 'companies'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'invoice_number' => 'required|string|max:50|unique:invoices,invoice_number,' . $invoice->id,
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'status' => 'required|in:draft,issued,partially_paid,paid,cancelled',
            'seller_name' => 'nullable|string|max:255',
            'seller_voen' => 'nullable|string|max:20',
            'seller_bank_name' => 'nullable|string|max:255',
            'seller_iban' => 'nullable|string|max:50',
            'seller_bank_code' => 'nullable|string|max:20',
            'seller_bank_voen' => 'nullable|string|max:20',
            'seller_swift' => 'nullable|string|max:20',
            'payer_name' => 'required|string|max:255',
            'payer_voen' => 'nullable|string|max:20',
            'contract_reference' => 'nullable|string|max:50',
            'comment' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.amount' => 'required|numeric|min:0.01',
        ]);

        DB::transaction(function () use ($request, $invoice) {
            $lines = $request->input('lines');
            $totalAmount = collect($lines)->sum('amount');
            
            $invoiceData = $request->except('lines');
            $invoiceData['total_amount'] = $totalAmount;
            
            $invoice->update($invoiceData);
            
            // Delete old lines and re-insert
            $invoice->lines()->delete();
            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                ]);
            }
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Инвойс успешно обновлен.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        if ($invoice->payments()->where('status', 'confirmed')->exists()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Невозможно удалить инвойс, по которому уже зарегистрированы платежи.');
        }

        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', 'Инвойс успешно удален.');
    }

    /**
 * AJAX — контракты компании для формы инвойса
 */
    public function getContracts(Company $company)
    {
        $contracts = $company->contracts()
            ->where('status', 'active')
            ->get(['id', 'contract_number', 'start_date', 'end_date']);

        return response()->json($contracts);
    }

    /**
     * AJAX — заказы и подписки контракта для формы инвойса
     */
    public function getContractItems(Contract $contract)
    {
        $orders = $contract->orders()
            ->where('status', '!=', 'cancelled')
            ->with('serviceType')
            ->get()
            ->map(fn($o) => [
                'id'          => $o->id,
                'type'        => 'order',
                'description' => $o->serviceType->name,
                'amount'      => $o->price,
                'terms'       => $o->payment_terms,
            ]);

        $subscriptions = $contract->subscriptions()
            ->where('status', 'active')
            ->with('serviceType')
            ->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'type'        => 'subscription',
                'description' => $s->serviceType->name . ' (' . $s->billing_period . ')',
                'amount'      => $s->amount,
                'terms'       => $s->payment_terms,
            ]);

        return response()->json([
            'orders'        => $orders,
            'subscriptions' => $subscriptions,
        ]);
    }
}
