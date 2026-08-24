<?php

namespace App\Http\Controllers\Web;

use App\Actions\Companies\DeleteCompany;
use App\Exceptions\CompanyDeletionException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Services\CompanyActivityPresenter;
use App\Services\CompanyActivityQuery;
use App\Services\InvoiceBillingPeriodPresenter;
use App\Services\InvoicePaymentSourceResolver;
use App\Services\OneTimeServiceDebtCalculator;
use App\Services\SubscriptionPeriodDebtCalculator;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyPageContext;
use App\Support\Navigation\AuthorizedLandingPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private const RUSSIAN_MONTHS = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Company::class);

        $canViewFinancials = Gate::allows(PermissionName::CompaniesFinancialsView->value);
        $allowedStatuses = ['active', 'suspended', 'archived'];
        $allowedSorts = $canViewFinancials ? ['name', 'debt'] : ['name'];
        $allowedDirections = ['asc', 'desc'];
        $status = in_array($request->input('status'), $allowedStatuses, true)
            ? $request->input('status')
            : '';
        $sort = in_array($request->input('sort'), $allowedSorts, true)
            ? $request->input('sort')
            : 'name';
        $direction = in_array($request->input('direction'), $allowedDirections, true)
            ? $request->input('direction')
            : 'asc';
        $search = trim((string) $request->input('search', ''));

        $query = Company::query()->select([
            'id',
            'type',
            'name',
            'short_name',
            'voen',
            'email',
            'phone',
            'status',
        ]);

        if ($canViewFinancials) {
            $query->addSelect([
                'calculated_debt' => $this->companyDebtSubquery(),
            ]);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%")
                    ->orWhere('voen', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $query->orderBy(
            $sort === 'debt' ? 'calculated_debt' : 'name',
            $direction
        )->orderBy('id', $direction);

        $paginationParameters = array_filter([
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'direction' => $direction,
        ], fn (string $value): bool => $value !== '');

        $companies = $query->paginate(10)->appends($paginationParameters);
        $companyIndexReturnUrl = route('companies.index', array_filter([
            ...$paginationParameters,
            'page' => $companies->currentPage() > 1 ? $companies->currentPage() : null,
        ], fn (string|int|null $value): bool => $value !== null && $value !== ''));

        if ($canViewFinancials) {
            $summaries = $this->financialSummaries($companies->getCollection());

            $companies->getCollection()->transform(function (Company $company) use ($summaries) {
                foreach ($summaries->get($company->id) as $key => $value) {
                    $company->setAttribute($key, $value);
                }

                return $company;
            });
        }

        return view('companies.index', compact(
            'companies',
            'search',
            'status',
            'sort',
            'direction',
            'canViewFinancials',
            'companyIndexReturnUrl'
        ));
    }

    public function autocomplete(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        if (Str::length($search) < 2) {
            return response()->json([]);
        }

        $companies = Company::query()
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%")
                    ->orWhere('voen', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'type', 'voen'])
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'type_label' => $company->type === 'company'
                    ? 'Юридическое лицо'
                    : 'Индивидуальный предприниматель',
                'voen' => $company->voen,
            ]);

        return response()->json($companies);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('create', Company::class);

        $backUrl = Gate::allows('viewAny', Company::class)
            ? route('companies.index')
            : $this->landingUrl();

        return view('companies.create', compact('backUrl'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Company::class);

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
            'comment' => 'nullable|string',
        ]);

        $company = Company::create($validated);

        $redirect = Gate::allows('view', $company)
            ? redirect()->route('companies.show', $company)
            : redirect()->to($this->landingUrl());

        return $redirect
            ->with('success', 'Компания успешно создана.');
    }

    /**
     * Display the specified resource.
     */
    public function show(
        Request $request,
        Company $company,
        SubscriptionPeriodDebtCalculator $periodDebtCalculator,
        OneTimeServiceDebtCalculator $oneTimeDebtCalculator,
        DeleteCompany $deleteCompany,
        InvoiceBillingPeriodPresenter $billingPeriodPresenter,
        InvoicePaymentSourceResolver $paymentSourceResolver,
        CompanyActivityQuery $activityQuery,
        CompanyActivityPresenter $activityPresenter,
    ) {
        Gate::authorize('view', $company);

        $canViewFinancials = Gate::allows('viewFinancials', $company);
        $canViewInvoices = $canViewFinancials
            && Gate::allows('viewAny', Invoice::class);
        $canViewPayments = $canViewFinancials
            && Gate::allows('viewAny', Payment::class);
        $canViewContracts = Gate::allows('viewAny', Contract::class);
        $companyCanBeDeleted = Gate::allows('delete', $company)
            && $deleteCompany->canDelete($company);

        $relations = [
            'contacts' => fn ($q) => $q->orderBy('first_name'),
        ];

        if ($canViewContracts) {
            $relations['contracts'] = fn ($q) => $q
                ->withCount(['orders', 'subscriptions'])
                ->orderBy('start_date', 'desc');
        }

        if ($canViewInvoices) {
            $relations['invoices'] = function ($q) use ($paymentSourceResolver) {
                $q->withSum([
                    'payments as confirmed_paid_amount' => fn ($paymentQuery) => $paymentQuery
                        ->where('status', 'confirmed'),
                ], 'amount');
                $q->withSum([
                    'payments as pending_amount' => fn ($paymentQuery) => $paymentQuery
                        ->where('status', 'pending'),
                ], 'amount');
                $paymentSourceResolver->addAggregates($q->getQuery());

                return $q->orderBy('due_date', 'desc');
            };
        }

        if ($canViewPayments) {
            $relations['payments'] = fn ($q) => $q
                ->when($canViewInvoices, fn ($paymentQuery) => $paymentQuery->with('invoice'))
                ->orderBy('payment_date', 'desc');
        }

        $company->load($relations);

        $activeTab = CompanyPageContext::activeTab($request);
        if (
            ($activeTab === 'invoices' && ! $canViewInvoices)
            || ($activeTab === 'payments' && ! $canViewPayments)
            || ($activeTab === 'contracts' && ! $canViewContracts)
        ) {
            $activeTab = 'contacts';
        }

        $returnContext = $this->companyReturnContext($request);

        $activityCategory = null;
        $activityPage = null;
        $activityEvents = collect();

        if ($activeTab === 'activity') {
            $activityCategory = CompanyActivityCategory::tryFrom((string) $request->query('activity_category'));
            $activityPage = $activityQuery->paginate(
                $request->user(),
                $company,
                $activityCategory,
                is_string($request->query('activity_cursor')) ? $request->query('activity_cursor') : null,
            );
            $activitySubjects = $activityQuery->availableSubjectIds($request->user(), $company, $activityPage);
            $activityEvents = collect($activityPage->items())
                ->map(fn ($event): array => $activityPresenter->present($event, $request->user(), $activitySubjects));
            $activityPage->appends(array_filter([
                'tab' => 'activity',
                'activity_category' => $activityCategory?->value,
            ]));
        }

        $viewData = compact(
            'company',
            'activeTab',
            'returnContext',
            'companyCanBeDeleted',
            'activityPage',
            'activityEvents',
            'activityCategory'
        );

        if ($canViewFinancials) {
            $stats = $this->financialSummaries(
                collect([$company]),
                $canViewInvoices ? $company->invoices : null
            )->get($company->id);
            $invoiceLines = InvoiceLine::query()
                ->whereHas(
                    'invoice',
                    fn ($query) => $query->where('company_id', $company->id)
                )
                ->with([
                    'invoice',
                    'subscription',
                    'order',
                    'allocations.payment:id,invoice_id,status',
                ])
                ->orderBy('id')
                ->get();

            $asOf = CarbonImmutable::today();
            $subscriptionPeriodDebts = $periodDebtCalculator->calculate($invoiceLines, $asOf);
            $oneTimeServiceDebts = $oneTimeDebtCalculator->calculate($invoiceLines, $asOf);
            $overdueRemaining = $this->addMoneyStrings(
                $subscriptionPeriodDebts['totals']['overdue_remaining'],
                $oneTimeServiceDebts['totals']['overdue_remaining']
            );

            $viewData += compact('stats', 'overdueRemaining');
        }

        if ($canViewInvoices) {
            $invoiceLinesByInvoice = $invoiceLines->groupBy('invoice_id');
            $invoiceBillingPeriods = $company->invoices->mapWithKeys(function (Invoice $invoice) use ($invoiceLinesByInvoice, $billingPeriodPresenter): array {
                $lines = $invoiceLinesByInvoice->get($invoice->id, collect());
                $invoice->setRelation('lines', $lines);

                return [$invoice->id => $billingPeriodPresenter->present($invoice, $lines)];
            });
            $invoicePaymentSources = $company->invoices->mapWithKeys(
                fn (Invoice $invoice): array => [
                    $invoice->id => $paymentSourceResolver->fromAggregates($invoice),
                ]
            );
            $subscriptionPeriodDebtGroups = array_values(array_filter(array_map(
                function (array $subscription): array {
                    $subscription['periods'] = array_values(array_map(
                        fn (array $period): array => $this->presentDebtPeriod($period),
                        array_filter(
                            $subscription['periods'],
                            fn (array $period): bool => $period['remaining'] !== '0.00'
                        )
                    ));

                    return $subscription;
                },
                $subscriptionPeriodDebts['subscriptions']
            ), fn (array $subscription): bool => $subscription['periods'] !== []));
            $subscriptionPeriodDebtAnomalyCount = count(array_unique(array_column(
                $subscriptionPeriodDebts['anomalies'],
                'invoice_line_id'
            )));
            $oneTimeServiceDebtLines = array_values(array_map(
                fn (array $line): array => [
                    ...$line,
                    'due_date_label' => $line['due_date'] === null
                        ? 'Не указан'
                        : CarbonImmutable::parse($line['due_date'])->format('d.m.Y'),
                ],
                array_filter(
                    $oneTimeServiceDebts['lines'],
                    fn (array $line): bool => $line['remaining'] !== '0.00'
                )
            ));

            $viewData += compact(
                'subscriptionPeriodDebts',
                'subscriptionPeriodDebtGroups',
                'subscriptionPeriodDebtAnomalyCount',
                'oneTimeServiceDebts',
                'oneTimeServiceDebtLines',
                'invoiceBillingPeriods',
                'invoicePaymentSources'
            );
        }

        return view('companies.show', $viewData);
    }

    private function addMoneyStrings(string $left, string $right): string
    {
        $minor = static function (string $amount): int {
            if (preg_match('/^(\d+)\.(\d{2})$/', $amount, $matches) !== 1) {
                throw new \LogicException("Invalid normalized money value: {$amount}.");
            }

            return ((int) $matches[1] * 100) + (int) $matches[2];
        };
        $total = $minor($left) + $minor($right);

        return intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }

    private function presentDebtPeriod(array $period): array
    {
        $periodStart = CarbonImmutable::parse($period['period_start']);
        $periodEnd = CarbonImmutable::parse($period['period_end']);
        $isFullMonth = $periodStart->day === 1
            && $periodEnd->isSameDay($periodStart->endOfMonth());

        return [
            ...$period,
            'period_label' => $isFullMonth
                ? self::RUSSIAN_MONTHS[$periodStart->month].' '.$periodStart->year
                : $periodStart->format('d.m.Y').'–'.$periodEnd->format('d.m.Y'),
            'due_date_label' => $period['due_date'] === null
                ? 'Не указан'
                : CarbonImmutable::parse($period['due_date'])->format('d.m.Y'),
        ];
    }

    /**
     * Calculate current financial totals from real issued invoices only.
     *
     * @param  Collection<int, Company>  $companies
     * @param  Collection<int, Invoice>|null  $loadedInvoices
     * @return Collection<int, array{total_invoiced: float, total_paid: float, total_debt: float, credit_balance: float}>
     */
    private function financialSummaries(Collection $companies, ?Collection $loadedInvoices = null): Collection
    {
        $companyIds = $companies->pluck('id')->values();

        if ($companyIds->isEmpty()) {
            return collect();
        }

        (new Company)->newCollection($companies->all())->loadMissing('creditBalance');

        $invoices = $loadedInvoices ?? Invoice::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->withSum([
                'payments as confirmed_paid_amount' => fn ($query) => $query->where('status', 'confirmed'),
            ], 'amount')
            ->get(['id', 'company_id', 'total_amount', 'status']);

        $eligibleInvoices = $invoices->whereIn('status', ['issued', 'partially_paid', 'paid']);

        return $companies->mapWithKeys(function (Company $company) use ($eligibleInvoices) {
            $companyInvoices = $eligibleInvoices->where('company_id', $company->id);

            return [
                $company->id => [
                    'total_invoiced' => round((float) $companyInvoices->sum('total_amount'), 2),
                    'total_paid' => round((float) $companyInvoices->sum(
                        fn (Invoice $invoice) => $invoice->applied_amount
                    ), 2),
                    'total_debt' => round((float) $companyInvoices->sum(
                        fn (Invoice $invoice) => $invoice->remaining_amount
                    ), 2),
                    'credit_balance' => round((float) ($company->creditBalance?->amount ?? 0), 2),
                ],
            ];
        });
    }

    private function companyDebtSubquery()
    {
        $confirmedPayments = "(
            SELECT COALESCE(SUM(company_debt_payments.amount), 0)
            FROM payments AS company_debt_payments
            WHERE company_debt_payments.invoice_id = invoices.id
              AND company_debt_payments.status = 'confirmed'
        )";

        return Invoice::query()
            ->selectRaw("COALESCE(SUM(CASE
                WHEN {$confirmedPayments} >= invoices.total_amount THEN 0
                ELSE invoices.total_amount - {$confirmedPayments}
            END), 0)")
            ->whereColumn('invoices.company_id', 'companies.id')
            ->whereIn('invoices.status', ['issued', 'partially_paid', 'paid']);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Company $company)
    {
        Gate::authorize('update', $company);

        $returnContext = $this->companyEditReturnContext($request, $company);

        return view('companies.edit', compact('company', 'returnContext'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Company $company)
    {
        Gate::authorize('update', $company);

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
            'comment' => 'nullable|string',
        ]);

        $company->update($validated);

        if (! Gate::allows('view', $company)) {
            return redirect()->to($this->landingUrl())
                ->with('success', 'Данные компании успешно обновлены.');
        }

        $returnContext = $this->companyEditReturnContext($request, $company);

        return redirect()->route($returnContext['route'], $returnContext['route_parameters'])
            ->with('success', 'Данные компании успешно обновлены.');
    }

    private function companyEditReturnContext(Request $request, Company $company): array
    {
        if (! Gate::allows('view', $company)) {
            return [
                'origin' => 'landing',
                'url' => $this->landingUrl(),
                'label' => 'Назад',
                'route' => null,
                'route_parameters' => [],
                'hidden' => [],
            ];
        }

        if ($request->input('origin') !== 'index') {
            return [
                'origin' => 'show',
                'url' => route('companies.show', $company),
                'label' => 'Назад к просмотру',
                'route' => 'companies.show',
                'route_parameters' => ['company' => $company],
                'hidden' => ['origin' => 'show'],
            ];
        }

        $parameters = [];
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $parameters['search'] = $search;
        }
        if (in_array($request->input('status'), ['active', 'suspended', 'archived'], true)) {
            $parameters['status'] = $request->input('status');
        }
        if (in_array($request->input('sort'), ['name', 'debt'], true)) {
            $parameters['sort'] = $request->input('sort');
        }
        if (in_array($request->input('direction'), ['asc', 'desc'], true)) {
            $parameters['direction'] = $request->input('direction');
        }
        $page = filter_var($request->input('page'), FILTER_VALIDATE_INT);
        if ($page !== false && $page > 0) {
            $parameters['page'] = $page;
        }

        return [
            'origin' => 'index',
            'url' => route('companies.index', $parameters),
            'label' => 'Назад к компаниям',
            'route' => 'companies.index',
            'route_parameters' => $parameters,
            'hidden' => ['origin' => 'index', ...$parameters],
        ];
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Company $company, DeleteCompany $deleteCompany)
    {
        Gate::authorize('delete', $company);

        try {
            $deleteCompany->handle($company);
        } catch (CompanyDeletionException $exception) {
            $redirect = Gate::allows('view', $company)
                ? redirect()->route('companies.show', $company)
                : redirect()->to($this->landingUrl());

            return $redirect->with('error', $exception->getMessage());
        }

        if (! Gate::allows('viewAny', Company::class)) {
            return redirect()->to($this->landingUrl())
                ->with('success', 'Компания успешно удалена.');
        }

        return redirect()->route('companies.index')
            ->with('success', 'Компания успешно удалена.');
    }

    private function landingUrl(): string
    {
        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }

    /**
     * Resolve a safe list page to return to without accepting open redirects.
     */
    private function companyReturnContext(Request $request): array
    {
        $fallback = [
            'url' => route('companies.index'),
            'label' => 'Назад к компаниям',
            'is_contextual' => false,
        ];
        $candidate = $request->input('return_url');

        if (! is_string($candidate) || trim($candidate) === '' || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return $fallback;
        }

        if (isset($parts['scheme']) && strtolower($parts['scheme']) !== strtolower($request->getScheme())) {
            return $fallback;
        }

        if (isset($parts['host']) && strtolower($parts['host']) !== strtolower($request->getHost())) {
            return $fallback;
        }

        if (isset($parts['host'])) {
            $candidatePort = $parts['port'] ?? (strtolower($parts['scheme'] ?? $request->getScheme()) === 'https' ? 443 : 80);

            if ($candidatePort !== $request->getPort()) {
                return $fallback;
            }
        } elseif (! str_starts_with($candidate, '/')) {
            return $fallback;
        }

        $path = '/'.ltrim($parts['path'] ?? '/', '/');
        $allowedDestinations = [
            parse_url(route('invoices.index'), PHP_URL_PATH) => 'Назад к инвойсам',
            parse_url(route('contracts.index'), PHP_URL_PATH) => 'Назад к договорам',
            parse_url(route('companies.index'), PHP_URL_PATH) => 'Назад к компаниям',
        ];

        if (! isset($allowedDestinations[$path])) {
            return $fallback;
        }

        return [
            'url' => $candidate,
            'label' => $allowedDestinations[$path],
            'is_contextual' => true,
        ];
    }
}
