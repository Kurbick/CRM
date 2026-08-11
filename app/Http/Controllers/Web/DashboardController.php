<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use App\Support\DashboardFinancials;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(DashboardFinancials $financials): View
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

        if ($abilities['invoices'] || $abilities['payments']) {
            $financialOverview = $financials->overview(now()->toDateString());

            if ($abilities['invoices']) {
                $overview['total_invoiced'] = $financialOverview['total_invoiced'];
                $overview['overdue_count'] = $financialOverview['overdue_count'];
                $overview['overdue_amount'] = $financialOverview['overdue_amount'];
            }

            if ($abilities['payments']) {
                $overview['total_paid'] = $financialOverview['total_paid'];
            }

            if ($abilities['global_debt']) {
                $overview['total_debt'] = $financialOverview['total_debt'];
            }
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

            if ($abilities['company_invoices']) {
                $companyQuery
                    ->with([
                        'invoices' => function ($query) use ($financials): void {
                            $financials->constrainOutstanding($query)
                                ->select(['id', 'company_id', 'due_date', 'total_amount'])
                                ->orderBy('due_date')
                                ->orderBy('id')
                                ->limit(1);
                            $financials->addRemainingAmount($query);
                        },
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

            $companyModels = $companyQuery->get();
            $companyFinancials = ($abilities['company_debt'] || $abilities['company_invoices'])
                ? $financials->byCompany($companyModels->pluck('id'), now()->toDateString())
                : collect();

            $companies = $companyModels->map(function (Company $company) use ($abilities, $companyFinancials): array {
                $row = [
                    'model' => $company,
                    'name' => $company->name,
                    'status' => $company->status,
                ];

                if ($abilities['company_debt']) {
                    $row['total_debt'] = $companyFinancials->get($company->id)?->total_debt ?? '0.00';
                }

                if ($abilities['company_invoices']) {
                    $nextInvoice = $company->invoices->first();
                    $row['has_overdue'] = (int) ($companyFinancials->get($company->id)?->overdue_count ?? 0) > 0;
                    $row['next_due_date'] = $nextInvoice?->due_date;
                    $row['next_due_amount'] = $nextInvoice?->dashboard_remaining_amount;
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
