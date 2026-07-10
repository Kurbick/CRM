<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
{
    $totalInvoiced = Invoice::whereNotIn('status', ['cancelled'])->sum('total_amount');
    $totalPaid = Payment::where('status', 'confirmed')
            ->where('comment', 'not like', '%Credit Balance%')
            ->sum('amount');

    $overview = [
        'total_invoiced'       => $totalInvoiced,
        'total_paid'           => $totalPaid,
        'total_debt'           => max(0, $totalInvoiced - $totalPaid), // исправлено
        'overdue_count'        => Invoice::whereNotIn('status', ['paid', 'cancelled'])
                                    ->where('due_date', '<', now()->toDateString())
                                    ->count(),
        'overdue_amount'       => Invoice::whereNotIn('status', ['paid', 'cancelled'])
                                    ->where('due_date', '<', now()->toDateString())
                                    ->sum('total_amount'),
        'active_companies'     => Company::where('status', 'active')->count(),
        'active_subscriptions' => Subscription::where('status', 'active')->count(),
        // убрали $company->creditBalance — $company здесь не существует
    ];

    $companies = Company::where('status', '!=', 'archived')
        ->with([
            'payments' => fn($q) => $q->where('status', 'confirmed')
                                      ->orderBy('payment_date', 'desc')
                                      ->limit(1),
            'invoices' => fn($q) => $q->whereNotIn('status', ['paid', 'cancelled'])
                                      ->orderBy('due_date', 'asc')
                                      ->limit(1),
        ])
        ->get()
        ->map(function ($company) {
            $invoiced = Invoice::where('company_id', $company->id)
                ->whereNotIn('status', ['cancelled'])->sum('total_amount');
            $paid = Payment::where('company_id', $company->id)
                ->where('status', 'confirmed')
                ->where('comment', 'not like', '%Credit Balance%')
                ->sum('amount');

            return [
                'id'                => $company->id,
                'name'              => $company->name,
                'status'            => $company->status,
                'total_debt'        => max(0, $invoiced - $paid), // исправлено
                'credit_balance'    => $company->creditBalance?->amount ?? 0, // перенесли сюда
                'has_overdue'       => Invoice::where('company_id', $company->id)
                                        ->whereNotIn('status', ['paid', 'cancelled'])
                                        ->where('due_date', '<', now()->toDateString())
                                        ->exists(),
                'last_payment_date' => $company->payments->first()?->payment_date,
                'next_due_date'     => $company->invoices->first()?->due_date,
                'next_due_amount'   => $company->invoices->first()?->total_amount,
            ];
        });

    return view('dashboard', compact('overview', 'companies'));
}
}