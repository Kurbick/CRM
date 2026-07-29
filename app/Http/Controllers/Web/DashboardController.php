<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        Gate::authorize(PermissionName::DashboardView->value);

        $abilities = [
            'companies' => Gate::allows('viewAny', Company::class),
            'create_companies' => Gate::allows('create', Company::class),
            'contracts' => Gate::allows('viewAny', Contract::class),
            'invoices' => Gate::allows('viewAny', Invoice::class),
            'payments' => Gate::allows('viewAny', Payment::class),
            'company_financials' => Gate::allows(PermissionName::CompaniesFinancialsView->value),
        ];
        $abilities['global_debt'] = $abilities['invoices']
            && $abilities['payments']
            && $abilities['company_financials'];
        $abilities['company_debt'] = $abilities['companies'] && $abilities['global_debt'];
        $abilities['company_invoices'] = $abilities['companies'] && $abilities['invoices'];
        $abilities['company_payments'] = $abilities['companies'] && $abilities['payments'];

        $overview = [];

        if ($abilities['invoices']) {
            $today = now()->toDateString();
            $invoiceOverview = Invoice::query()
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status NOT IN (?) THEN total_amount ELSE 0 END), 0) as total_invoiced',
                    ['cancelled']
                )
                ->selectRaw(
                    'SUM(CASE WHEN status NOT IN (?, ?) AND due_date < ? THEN 1 ELSE 0 END) as overdue_count',
                    ['paid', 'cancelled', $today]
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status NOT IN (?, ?) AND due_date < ? THEN total_amount ELSE 0 END), 0) as overdue_amount',
                    ['paid', 'cancelled', $today]
                )
                ->firstOrFail();

            $overview['total_invoiced'] = $invoiceOverview->total_invoiced;
            $overview['overdue_count'] = (int) $invoiceOverview->overdue_count;
            $overview['overdue_amount'] = $invoiceOverview->overdue_amount;
        }

        if ($abilities['payments']) {
            $overview['total_paid'] = Payment::query()
                ->where('status', 'confirmed')
                ->where('comment', 'not like', '%Credit Balance%')
                ->sum('amount');
        }

        if ($abilities['global_debt']) {
            $overview['total_debt'] = max(
                0,
                $overview['total_invoiced'] - $overview['total_paid']
            );
        }

        if ($abilities['companies']) {
            $overview['active_companies'] = Company::query()
                ->where('status', 'active')
                ->count();
        }

        if ($abilities['contracts']) {
            $overview['active_subscriptions'] = Subscription::query()
                ->where('status', 'active')
                ->count();
        }

        $companies = collect();

        if ($abilities['companies']) {
            $companyQuery = Company::query()
                ->select(['id', 'name', 'status'])
                ->where('status', '!=', 'archived');

            if ($abilities['company_debt']) {
                $companyQuery
                    ->withSum([
                        'invoices as dashboard_invoiced' => fn ($query) => $query
                            ->whereNotIn('status', ['cancelled']),
                    ], 'total_amount')
                    ->withSum([
                        'payments as dashboard_paid' => fn ($query) => $query
                            ->where('status', 'confirmed')
                            ->where('comment', 'not like', '%Credit Balance%'),
                    ], 'amount');
            }

            if ($abilities['company_invoices']) {
                $companyQuery
                    ->withExists([
                        'invoices as dashboard_has_overdue' => fn ($query) => $query
                            ->whereNotIn('status', ['paid', 'cancelled'])
                            ->where('due_date', '<', now()->toDateString()),
                    ])
                    ->with([
                        'invoices' => fn ($query) => $query
                            ->select(['id', 'company_id', 'due_date', 'total_amount'])
                            ->whereNotIn('status', ['paid', 'cancelled'])
                            ->orderBy('due_date')
                            ->limit(1),
                    ]);
            }

            if ($abilities['company_payments']) {
                $companyQuery->with([
                    'payments' => fn ($query) => $query
                        ->select(['id', 'company_id', 'payment_date'])
                        ->where('status', 'confirmed')
                        ->orderByDesc('payment_date')
                        ->limit(1),
                ]);
            }

            $companies = $companyQuery->get()->map(function (Company $company) use ($abilities): array {
                $row = [
                    'model' => $company,
                    'name' => $company->name,
                    'status' => $company->status,
                ];

                if ($abilities['company_debt']) {
                    $row['total_debt'] = max(
                        0,
                        $company->dashboard_invoiced - $company->dashboard_paid
                    );
                }

                if ($abilities['company_invoices']) {
                    $nextInvoice = $company->invoices->first();
                    $row['has_overdue'] = (bool) $company->dashboard_has_overdue;
                    $row['next_due_date'] = $nextInvoice?->due_date;
                    $row['next_due_amount'] = $nextInvoice?->total_amount;
                }

                if ($abilities['company_payments']) {
                    $row['last_payment_date'] = $company->payments->first()?->payment_date;
                }

                return $row;
            });
        }

        $hasDomainBlocks = $abilities['companies']
            || $abilities['contracts']
            || $abilities['invoices']
            || $abilities['payments'];

        return view('dashboard', compact(
            'abilities',
            'overview',
            'companies',
            'hasDomainBlocks'
        ));
    }
}
