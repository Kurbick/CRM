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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    private const MAX_FINANCIAL_BREAKDOWN_COMPANIES = 7;

    public function index(
        Request $request,
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

        $requestedPeriod = (string) $request->query('period', DashboardFinancials::PERIOD_THIS_MONTH);
        $validatedPeriod = $request->validate([
            'period' => ['nullable', Rule::in(DashboardFinancials::PERIOD_KEYS)],
            'date_from' => [
                'nullable',
                'date',
                Rule::requiredIf($requestedPeriod === 'custom'),
            ],
            'date_to' => [
                'nullable',
                'date',
                Rule::requiredIf($requestedPeriod === 'custom'),
                'after_or_equal:date_from',
            ],
        ]);
        $period = $financials->periodRange(
            $validatedPeriod['period'] ?? DashboardFinancials::PERIOD_THIS_MONTH,
            $validatedPeriod['date_from'] ?? null,
            $validatedPeriod['date_to'] ?? null,
            CarbonImmutable::today(),
        );

        $overview = [];
        $billingSummary = null;
        $financialDate = now()->toDateString();

        if ($abilities['billing']) {
            $billingSummary = $billingPreview->dashboardSummary(CarbonImmutable::now()->startOfMonth());
        }

        if ($abilities['invoices'] || $abilities['payments']) {
            $financialOverview = $financials->overview($financialDate);
            $periodOverview = $financials->periodOverview($period['from'], $period['to']);

            if ($abilities['invoices']) {
                $overview['total_invoiced'] = $periodOverview['total_invoiced'];
                $overview['overdue_count'] = $financialOverview['overdue_count'];
                $overview['overdue_amount'] = $financialOverview['overdue_amount'];
            }

            if ($abilities['payments']) {
                $overview['total_paid'] = $periodOverview['total_paid'];
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
        $overdueBreakdown = collect();

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
                ? $financials->byCompany($companyModels->pluck('id'), $financialDate)
                : collect();

            $companies = $companyModels->map(function (Company $company) use ($abilities, $companyFinancials): array {
                $row = [
                    'model' => $company,
                    'name' => $company->name,
                    'status' => $company->status,
                ];

                if ($abilities['company_debt']) {
                    $financialRow = $companyFinancials->get($company->id);
                    $row['total_debt'] = $financialRow?->total_debt ?? '0.00';
                    $row['overdue_amount'] = $financialRow?->overdue_amount ?? '0.00';
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
                    ->take(self::MAX_FINANCIAL_BREAKDOWN_COMPANIES)
                    ->values()
                    ->map(fn (array $company): array => [
                        'model' => $company['model'],
                        'name' => $company['name'],
                        'total_debt' => $company['total_debt'],
                    ]);
            }

            if ($abilities['company_debt']) {
                $overdueBreakdown = $companies
                    ->filter(fn (array $company): bool => (float) ($company['overdue_amount'] ?? 0) > 0
                        && Gate::allows('view', $company['model']))
                    ->sort(function (array $left, array $right): int {
                        $amountComparison = (float) $right['overdue_amount'] <=> (float) $left['overdue_amount'];

                        return $amountComparison !== 0
                            ? $amountComparison
                            : strcasecmp($left['name'], $right['name']);
                    })
                    ->take(self::MAX_FINANCIAL_BREAKDOWN_COMPANIES)
                    ->values()
                    ->map(fn (array $company): array => [
                        'model' => $company['model'],
                        'name' => $company['name'],
                        'overdue_amount' => $company['overdue_amount'],
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
            'overdueBreakdown',
            'hasDomainBlocks',
            'billingSummary',
            'period'
        ));
    }
}
