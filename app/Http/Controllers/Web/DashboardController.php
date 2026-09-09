<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\ActiveOrganizationContext;
use App\Services\BillingPeriodPreview;
use App\Support\Access\PermissionName;
use App\Support\DashboardFinancials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    private const MAX_DEBT_BREAKDOWN_COMPANIES = 7;

    public function index(
        DashboardFinancials $financials,
        ActiveOrganizationContext $organizationContext,
        BillingPeriodPreview $billingPreview,
    ): View
    {
        Gate::authorize(PermissionName::DashboardView->value);

        $abilities = [
            'companies' => Gate::allows('viewAny', Company::class),
            'create_companies' => Gate::allows('create', Company::class),
            'contracts' => Gate::allows('viewAny', Contract::class),
            'invoices' => Gate::allows('viewAny', Invoice::class),
            'payments' => Gate::allows('viewAny', Payment::class),
            'company_financials' => Gate::allows(PermissionName::CompaniesFinancialsView->value),
            'billing' => Gate::allows('create', Invoice::class),
        ];
        $abilities['global_debt'] = $abilities['invoices']
            && $abilities['payments']
            && $abilities['company_financials'];
        $abilities['company_debt'] = $abilities['companies'] && $abilities['global_debt'];
        $abilities['company_invoices'] = $abilities['companies'] && $abilities['invoices'];
        $abilities['company_payments'] = $abilities['companies'] && $abilities['payments'];

        $overview = [];
        $billingSummary = null;

        if ($abilities['billing']) {
            $billingSummary = $billingPreview->dashboardSummary(CarbonImmutable::now()->startOfMonth());
        }

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
            $overview['active_subscriptions'] = $billingSummary['active_subscription_count']
                ?? Subscription::query()
                    ->where('status', 'active')
                    ->whereHas('contract', function ($query) use ($organizationContext): void {
                        $organization = $organizationContext->resolve();
                        if ($organization === null) {
                            $query->whereRaw('1 = 0');
                        } else {
                            $organizationContext->scopeFor($query, $organization);
                        }
                    })
                    ->count();
        }

        $companies = collect();
        $debtBreakdown = collect();

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
                        ->whereHas('invoice', function ($invoiceQuery) use ($organizationContext): void {
                            $organization = $organizationContext->resolve();
                            if ($organization === null) {
                                $invoiceQuery->whereRaw('1 = 0');
                            } else {
                                $organizationContext->scopeFor($invoiceQuery, $organization);
                            }
                        })
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

            if ($abilities['company_debt']) {
                $debtBreakdown = $companies
                    ->filter(fn (array $company): bool => (float) ($company['total_debt'] ?? 0) > 0
                        && Gate::allows('view', $company['model']))
                    ->sortByDesc(fn (array $company): float => (float) $company['total_debt'])
                    ->take(self::MAX_DEBT_BREAKDOWN_COMPANIES)
                    ->values()
                    ->map(fn (array $company): array => [
                        'model' => $company['model'],
                        'name' => $company['name'],
                        'total_debt' => $company['total_debt'],
                    ]);
            }
        }

        $hasDomainBlocks = $abilities['companies']
            || $abilities['contracts']
            || $abilities['invoices']
            || $abilities['payments']
            || $abilities['billing'];

        return view('dashboard', compact(
            'abilities',
            'overview',
            'companies',
            'debtBreakdown',
            'hasDomainBlocks',
            'billingSummary'
        ));
    }
}
