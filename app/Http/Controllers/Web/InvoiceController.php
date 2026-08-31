<?php

namespace App\Http\Controllers\Web;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Invoices\CreateInvoice;
use App\Actions\Invoices\DeleteInvoice;
use App\Actions\Invoices\UpdateInvoice;
use App\Exceptions\Invoices\InvoiceDeletionException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoiceBillingPeriodPresenter;
use App\Services\InvoiceDueDateCalculator;
use App\Services\InvoiceEditabilityService;
use App\Services\InvoicePaymentAvailabilityService;
use App\Services\InvoicePaymentBreakdownPresenter;
use App\Services\InvoicePaymentSourceResolver;
use App\Services\InvoiceNumberService;
use App\Services\ActiveOrganizationContext;
use App\Services\SubscriptionBillingSchedule;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use App\Support\CompanyPageContext;
use App\Support\Invoices\InvoiceSellerSnapshot;
use App\Support\Navigation\AuthorizedLandingPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly CreateInvoice $createInvoice,
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
        private readonly InvoiceBillingPeriodPresenter $billingPeriodPresenter,
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService,
        private readonly InvoicePaymentBreakdownPresenter $paymentBreakdownPresenter,
        private readonly InvoicePaymentSourceResolver $paymentSourceResolver,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly InvoiceSellerSnapshot $sellerSnapshot,
        private readonly UpdateInvoice $updateInvoice,
        private readonly CompanyActivityRecorder $activityRecorder,
        private readonly InvoiceNumberService $invoiceNumberService,
        private readonly ActiveOrganizationContext $organizationContext,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Invoice::class);

        $search = trim((string) $request->input('search', ''));
        $activeCompanyId = $this->validFilterId($request->input('company_id'), Company::class);
        $activeContractId = $this->validFilterId($request->input('contract_id'), Contract::class);

        $organization = $this->organizationContext->resolve($request);
        $query = Invoice::query()
            ->tap(fn ($query) => $this->organizationContext->scopeFor($query, $organization))
            ->with('company')
            ->withSum([
                'payments as confirmed_paid_amount' => function ($paymentQuery) {
                    $paymentQuery->where('status', 'confirmed');
                },
            ], 'amount');
        $query->withSum([
            'payments as pending_amount' => function ($paymentQuery) {
                $paymentQuery->where('status', 'pending');
            },
        ], 'amount');

        $this->paymentSourceResolver->addAggregates($query);

        $allowedStatuses = [
            'draft',
            'issued',
            'partially_paid',
            'paid',
            'cancelled',
        ];

        $allowedSortColumns = [
            'issue_date',
            'due_date',
        ];

        $allowedSortDirections = [
            'asc',
            'desc',
        ];

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query
                    ->where('invoices.invoice_number', 'like', "%{$search}%")
                    ->orWhere('invoices.payer_name', 'like', "%{$search}%")
                    ->orWhere('invoices.contract_reference', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($companyQuery) use ($search) {
                        $companyQuery->where('companies.name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('contract', function ($contractQuery) use ($search) {
                        $contractQuery->where('contracts.contract_number', 'like', "%{$search}%");
                    });
            });
        }

        $activeStatuses = $this->normalizedStatuses($request, $allowedStatuses);
        if ($activeStatuses !== []) {
            $query->whereIn('status', $activeStatuses);
        }

        if ($activeCompanyId !== null) {
            $query->where('invoices.company_id', $activeCompanyId);
        }

        if ($activeContractId !== null) {
            $query->where('invoices.contract_id', $activeContractId);
        }

        $activeOverdue = $request->boolean('overdue');
        $activeUnpaid = $request->boolean('unpaid');
        if ($activeUnpaid) {
            $query->whereIn('status', ['issued', 'partially_paid']);
        }
        if ($activeOverdue) {
            $query->whereIn('status', ['issued'.'', 'partially_paid'])
                ->where('due_date', '<', now()->toDateString());
        }

        $sort = $request->input('sort', 'issue_date');
        $direction = $request->input('direction', 'desc');

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'issue_date';
        }

        if (! in_array($direction, $allowedSortDirections, true)) {
            $direction = 'desc';
        }

        $paginationParameters = [];
        if ($search !== '') {
            $paginationParameters['search'] = $search;
        }
        if ($activeCompanyId !== null) {
            $paginationParameters['company_id'] = $activeCompanyId;
        }
        if ($activeContractId !== null) {
            $paginationParameters['contract_id'] = $activeContractId;
        }
        if ($activeStatuses !== []) {
            $paginationParameters['statuses'] = $activeStatuses;
        }
        if ($activeOverdue) {
            $paginationParameters['overdue'] = 1;
        }
        if ($activeUnpaid) {
            $paginationParameters['unpaid'] = 1;
        }
        $paginationParameters['sort'] = $sort;
        $paginationParameters['direction'] = $direction;

        $invoices = $query
            ->with(['lines:id,invoice_id,period_start,period_end'])
            ->orderBy("invoices.{$sort}", $direction)
            ->orderByDesc('invoices.id')
            ->paginate(10)
            ->appends($paginationParameters);

        $companies = Company::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        $contracts = Contract::query()
            ->tap(fn ($query) => $this->organizationContext->scopeFor($query, $organization))
            ->orderBy('contract_number')
            ->get([
                'id',
                'company_id',
                'contract_number',
            ]);

        $invoicePaymentSources = $invoices->getCollection()
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => $this->paymentSourceResolver->fromAggregates($invoice),
            ]);
        $invoiceBillingPeriods = $invoices->getCollection()
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => $this->billingPeriodPresenter->present($invoice, $invoice->lines),
            ]);

        return view('invoices.index', compact(
            'invoices',
            'companies',
            'contracts',
            'invoicePaymentSources',
            'invoiceBillingPeriods',
            'activeStatuses',
            'search',
            'activeCompanyId',
            'activeContractId',
            'activeOverdue',
            'activeUnpaid'
        ));
    }

    /** @param list<string> $allowedStatuses @return list<string> */
    private function normalizedStatuses(Request $request, array $allowedStatuses): array
    {
        if ($request->has('statuses')) {
            $statuses = $request->input('statuses');

            return is_array($statuses)
                ? array_values(array_unique(array_values(array_filter(
                    $statuses,
                    fn (mixed $status): bool => is_string($status) && in_array($status, $allowedStatuses, true)
                ))))
                : [];
        }

        $legacyStatus = $request->input('status');

        return is_string($legacyStatus) && in_array($legacyStatus, $allowedStatuses, true)
            ? [$legacyStatus]
            : [];
    }

    private function validFilterId(mixed $value, string $modelClass): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        $id = (int) $value;

        return $modelClass::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        Gate::authorize('create', Invoice::class);

        $prefilledCompany = null;
        $prefilledContract = null;

        $contractId = $this->validFilterId($request->query('contract_id'), Contract::class);
        if ($contractId !== null) {
            $prefilledContract = Contract::query()
                ->whereKey($contractId)
                ->where('status', 'active')
                ->whereHas('company', fn ($query) => $query->where('status', 'active'))
                ->first();

            if ($prefilledContract !== null) {
                $prefilledContract->load([
                    'issuerOrganization',
                    'company:id,name,status',
                ]);
                $prefilledCompany = $prefilledContract->company;

                if ($prefilledContract->issuerOrganization !== null
                    && ! $prefilledContract->issuerOrganization->is_active) {
                    return $this->contractIssuerGuard('organizations.errors.contract_issuer_inactive');
                }
                if ($prefilledContract->issuerOrganization === null
                    && $this->organizationContext->singleActive() === null) {
                    return $this->contractIssuerGuard('organizations.errors.contract_issuer_missing');
                }
            }
        }

        if ($prefilledContract === null && $this->organizationContext->resolve($request) === null) {
            return $this->organizationContextGuard($request);
        }

        $companies = Company::where('status', 'active')
            ->orderBy('name')
            ->get();

        if ($prefilledCompany === null) {
            $companyId = $this->validFilterId($request->query('company_id'), Company::class);
            if ($companyId !== null) {
                $prefilledCompany = $companies->firstWhere('id', $companyId);
            }
        }

        if ($prefilledContract !== null && $prefilledCompany === null) {
            $prefilledContract = null;
        }

        if ($prefilledContract !== null) {
            $backUrl = route('contracts.show', $prefilledContract);
            $backLabel = __('contracts.actions.back_to_contract').' '.$prefilledContract->contract_number;
        } elseif ($prefilledCompany !== null) {
            $backUrl = route('companies.show', ['company' => $prefilledCompany, 'tab' => 'invoices']);
            $backLabel = __('invoices.actions.back_to_company', ['company' => $prefilledCompany->name]);
        } else {
            $backUrl = Gate::allows('viewAny', Invoice::class)
                ? route('invoices.index')
                : $this->landingUrl();
            $backLabel = __('invoices.actions.back_to_list');
        }

        $companyContext = $prefilledCompany !== null && Gate::allows('view', $prefilledCompany)
            ? CompanyPageContext::resolve($request, $prefilledCompany, 'invoices')
            : null;

        $oldContractId = $prefilledContract !== null
            ? $prefilledContract->id
            : $this->validFilterId(old('contract_id'), Contract::class);
        $oldCompanyId = $prefilledCompany !== null
            ? $prefilledCompany->id
            : $this->validFilterId(old('company_id'), Company::class);
        $oldSubscriptionIds = collect(old('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->pluck('subscription_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $oldSubscriptions = collect();
        if ($oldContractId !== null && $oldCompanyId !== null && $oldSubscriptionIds->isNotEmpty()) {
            $oldSubscriptions = Subscription::query()
                ->whereIn('id', $oldSubscriptionIds)
                ->where('contract_id', $oldContractId)
                ->whereHas('contract', fn ($query) => $query->where('company_id', $oldCompanyId))
                ->where('status', 'active')
                ->get()
                ->keyBy('id');
        }
        $oldSubscriptionAmounts = $oldSubscriptions->pluck('amount', 'id');
        $oldSubscriptionOccurrences = collect(old('lines', []))
            ->filter(fn (mixed $line): bool => is_array($line) && is_numeric($line['subscription_id'] ?? null))
            ->mapWithKeys(function (array $line) use ($oldSubscriptions): array {
                $subscription = $oldSubscriptions->get((int) $line['subscription_id']);
                $periodCount = filter_var($line['period_count'] ?? 1, FILTER_VALIDATE_INT);

                if (! $subscription
                    || $periodCount === false
                    || $periodCount < 1
                    || $periodCount > SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE
                    || empty($line['expected_period_start'])) {
                    return [];
                }

                try {
                    $occurrences = $this->billingSchedule->occurrenceChain(
                        $subscription,
                        CarbonImmutable::parse($line['expected_period_start'])->startOfDay(),
                        $periodCount,
                    );
                } catch (\Throwable) {
                    return [];
                }

                return [(int) $subscription->id => collect($occurrences)
                    ->map(fn (array $occurrence): array => [
                        'period_start' => $occurrence['period_start']->toDateString(),
                        'period_end' => $occurrence['period_end']->toDateString(),
                    ])
                    ->all()];
            });

        $numberPreview = null;
        $numberingError = null;
        $organization = $prefilledContract !== null
            ? ($prefilledContract->issuerOrganization ?? $this->organizationContext->singleActive())
            : $this->organizationContext->resolve($request);
        $invoiceVatPolicy = [
            'enabled' => (bool) $organization?->is_vat_payer,
            'rate' => $organization?->vat_rate !== null ? (string) $organization->vat_rate : null,
        ];
        if ($organization !== null) {
            try {
                $numberPreview = $this->invoiceNumberService->preview(
                    $organization,
                    CarbonImmutable::parse(old('issue_date', now()->toDateString()))->year,
                );
            } catch (ValidationException $exception) {
                $numberingError = (string) ($exception->errors()['organization'][0] ?? $exception->getMessage());
            }
        }

        return view('invoices.create', compact(
            'companies',
            'backUrl',
            'backLabel',
            'prefilledCompany',
            'prefilledContract',
            'companyContext',
            'oldSubscriptionAmounts',
            'oldSubscriptionOccurrences',
            'numberPreview',
            'numberingError',
            'invoiceVatPolicy',
        ));
    }

    public function numberPreview(Request $request)
    {
        $validated = $request->validate([
            'issue_date' => ['required', 'date'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
        ]);

        if (! empty($validated['invoice_id'])) {
            $invoice = Invoice::query()->findOrFail($validated['invoice_id']);
            Gate::authorize('update', $invoice);
        } else {
            Gate::authorize('create', Invoice::class);
        }

        $previewInvoice = ! empty($validated['invoice_id'])
            ? Invoice::query()->with(['contract.issuerOrganization', 'issuerOrganization'])->findOrFail($validated['invoice_id'])
            : null;
        if ($previewInvoice !== null) {
            $organization = $previewInvoice->contract?->issuerOrganization
                ?? ($previewInvoice->contract === null ? $previewInvoice->issuerOrganization : null);
        } elseif (! empty($validated['contract_id'])) {
            $contract = Contract::query()
                ->with('issuerOrganization')
                ->findOrFail($validated['contract_id']);
            $organization = $contract->issuerOrganization ?? $this->organizationContext->singleActive();
            if ($organization !== null && ! $organization->is_active) {
                return response()->json([
                    'message' => __('organizations.errors.contract_issuer_inactive'),
                ], 422);
            }
        } else {
            $organization = $this->organizationContext->resolve($request);
        }
        if (! $organization) {
            return response()->json([
                'message' => $previewInvoice?->contract !== null || ! empty($validated['contract_id'])
                    ? __('organizations.errors.contract_issuer_missing')
                    : __($this->organizationContext->missingContextMessage()),
            ], 422);
        }

        return response()->json($this->invoiceNumberService->preview(
            $organization,
            CarbonImmutable::parse($validated['issue_date'])->year,
        ));
    }

    private function organizationContextGuard(Request $request)
    {
        return view('organization-context.required', [
            'state' => $this->organizationContext->activeOrganizations()->isEmpty() ? 'none' : 'selection',
            'canManageOrganizations' => $request->user()?->hasRole('administrator') ?? false,
        ]);
    }

    private function contractIssuerGuard(string $messageKey)
    {
        return view('organization-context.required', [
            'state' => 'contract_inactive',
            'title' => __('invoices.form.new_title'),
            'message' => __($messageKey),
            'canManageOrganizations' => false,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Invoice::class);

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'contract_id' => 'required|exists:contracts,id',
            'invoice_number' => 'nullable|string|max:50|unique:invoices,invoice_number',
            'invoice_number_sequence' => ['nullable', 'integer', 'min:1'],
            'invoice_number_manual' => ['nullable', 'boolean'],
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'subtotal_amount' => 'prohibited',
            'vat_enabled' => 'prohibited',
            'vat_rate' => 'prohibited',
            'vat_amount' => 'prohibited',
            'seller_name' => 'prohibited',
            'seller_voen' => 'prohibited',
            'seller_bank_name' => 'prohibited',
            'seller_iban' => 'prohibited',
            'seller_bank_code' => 'prohibited',
            'seller_bank_voen' => 'prohibited',
            'seller_swift' => 'prohibited',
            'comment' => 'nullable|string',
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => [
                'array',
                function (string $attribute, mixed $line, \Closure $fail): void {
                    if (empty($line['subscription_id']) && empty($line['order_id'])) {
                        $fail(__('invoices.errors.subject_not_in_contract'));
                    }
                },
            ],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'lines.*.order_id' => ['nullable', 'exists:orders,id'],
            'lines.*.period_count' => ['nullable', 'integer', 'min:1', 'max:'.SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE],
            'lines.*.expected_period_start' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.period_start' => ['nullable', 'date'],
            'lines.*.period_end' => ['nullable', 'date'],
        ]);

        foreach ($validated['lines'] as $index => $line) {
            if (empty($line['subscription_id']) && array_key_exists('period_count', $line) && $line['period_count'] !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.period_count" => __('invoices.form.periods_subscription_only'),
                ]);
            }
        }

        $company = Company::findOrFail($validated['company_id']);
        $contract = Contract::query()
            ->whereKey($validated['contract_id'])
            ->where('company_id', $company->id)
            ->first();

        if (! $contract) {
            return back()
                ->withErrors([
                    'contract_id' => __('invoices.errors.contract_company_mismatch'),
                ])
                ->withInput();
        }

        $invoice = $this->createInvoice->execute(
            $company,
            $contract,
            $validated,
            array_values($validated['lines']),
            canonicalizeSubjectAmounts: true,
            actor: $request->user(),
        );

        $companyContext = $this->invoiceCompanyContext($request, $invoice);

        return $this->mutationRedirect($invoice, $companyContext['query'])
            ->with('success', __('invoices.flash.draft_saved'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'company',
            'contract',
            'issuerOrganization',
            'lines',
        ]);

        $canViewPaymentHistory = Gate::allows('viewAny', Payment::class);

        if ($canViewPaymentHistory) {
            $invoice->load([
                'payments' => fn ($query) => $query
                    ->with(['allocations', 'creditBalanceEntries'])
                    ->orderByDesc('payment_date')
                    ->orderByDesc('id'),
            ]);
        } else {
            $calculationPayments = $invoice->payments()
                ->select(['id', 'invoice_id', 'status', 'amount'])
                ->with('allocations:id,payment_id,invoice_line_id,amount')
                ->orderByDesc('id')
                ->get()
                ->each(fn (Payment $payment) => $payment->setRelation(
                    'creditBalanceEntries',
                    $payment->newCollection()
                ));

            $invoice->setRelation('payments', $calculationPayments);
        }

        $companyContext = $this->invoiceCompanyContext($request, $invoice);
        $paymentBreakdown = $this->paymentBreakdownPresenter->present($invoice);
        $invoiceBillingPeriod = $this->billingPeriodPresenter->present($invoice, $invoice->lines);
        $paymentAvailability = $this->paymentAvailabilityService->evaluate($invoice);
        $editability = $this->editabilityService->evaluate($invoice);
        $hasPayments = $invoice->payments->isNotEmpty();
        $actionablePayments = $this->actionablePayments($invoice);

        $canApplyCredit = in_array($invoice->status, ['issued', 'partially_paid'], true)
            && Gate::allows('create', [Payment::class, $invoice])
            && Gate::allows('viewFinancials', $invoice->company);
        $creditBalance = null;
        $creditBalanceMinor = 0;
        $creditMaximumMinor = 0;
        if ($canApplyCredit) {
            $creditBalance = $invoice->company->creditBalances()
                ->where('organization_id', $invoice->issuer_organization_id)
                ->first();
            if ($creditBalance === null && Organization::query()->active()->count() === 1) {
                $creditBalance = $invoice->company->creditBalance()
                    ->whereNull('organization_id')
                    ->first();
            }
            if ($creditBalance !== null) {
                $creditBalanceMinor = $this->paymentAvailabilityService->toMinorUnits(
                    $creditBalance->getRawOriginal('amount')
                );
                $creditMaximumMinor = min(
                    $creditBalanceMinor,
                    $paymentAvailability['available_minor'],
                );
            }
        }

        if ($canViewPaymentHistory) {
            $paymentsById = $invoice->payments->keyBy('id');
            $paymentSource = $this->paymentSourceResolver->fromLoadedInvoice($invoice);
        } else {
            $invoice->setAttribute(
                'confirmed_paid_amount',
                $invoice->payments->where('status', 'confirmed')->sum('amount')
            );
            $paymentBreakdown = [
                'lineRows' => $paymentBreakdown['lineRows'],
                'totals' => $paymentBreakdown['totals'],
            ];

            $invoice->unsetRelation('payments');
        }

        $viewData = compact(
            'invoice',
            'companyContext',
            'paymentBreakdown',
            'invoiceBillingPeriod',
            'paymentAvailability',
            'editability',
            'hasPayments',
            'actionablePayments'
        );
        $viewData += compact(
            'canApplyCredit',
            'creditBalance',
            'creditBalanceMinor',
            'creditMaximumMinor',
        );
        $viewData['sellerFallback'] = $this->sellerSnapshot->legacyFallback();

        if ($canViewPaymentHistory) {
            $viewData += compact('paymentsById', 'paymentSource');
        }

        return view('invoices.show', $viewData);
    }

    /**
     * Apply an exact operator-requested amount from the Company's Credit Balance.
     */
    public function applyCredit(
        Request $request,
        Invoice $invoice,
        ApplyCreditToInvoice $applyCreditToInvoice,
    ): RedirectResponse {
        Gate::authorize('view', $invoice);
        Gate::authorize('create', [Payment::class, $invoice]);

        $invoice->loadMissing('company');
        Gate::authorize('viewFinancials', $invoice->company);

        try {
            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
                'expected_credit_balance_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
                'expected_available_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            ], [
                'amount.required' => __('invoices.credit.validation.amount_required'),
                'amount.numeric' => __('invoices.credit.validation.amount_numeric'),
                'amount.decimal' => __('invoices.credit.validation.amount_decimal'),
                'amount.min' => __('invoices.credit.validation.amount_min'),
                'amount.max' => __('invoices.credit.validation.amount_max'),
                'expected_credit_balance_minor.*' => __('invoices.credit.validation.changed'),
                'expected_available_minor.*' => __('invoices.credit.validation.changed'),
            ]);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['amount'])) {
                $errors['credit_amount'] = $errors['amount'];
                unset($errors['amount']);
            }

            return redirect()->route('invoices.show', $invoice)
                ->withErrors($errors)
                ->withInput()
                ->with('credit_dialog_open', true);
        }

        try {
            $requestedMinor = $this->paymentAvailabilityService->toMinorUnits($validated['amount']);
            $result = $applyCreditToInvoice->executeManual(
                invoice: $invoice,
                requestedAmountMinor: $requestedMinor,
                expectedCreditBalanceMinor: (int) $validated['expected_credit_balance_minor'],
                expectedAvailableMinor: (int) $validated['expected_available_minor'],
                actor: $request->user(),
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            foreach (['credit', 'expected_credit_balance_minor', 'expected_available_minor'] as $key) {
                if (isset($errors[$key])) {
                    $errors['credit_amount'] = array_merge(
                        $errors['credit_amount'] ?? [],
                        $errors[$key],
                    );
                    unset($errors[$key]);
                }
            }

            return redirect()->route('invoices.show', $invoice)
                ->withErrors($errors)
                ->withInput()
                ->with('credit_dialog_open', true);
        } catch (\LogicException) {
            return redirect()->route('invoices.show', $invoice)
                ->withErrors(['credit_amount' => __('invoices.credit.validation.invalid_amount')])
                ->withInput()
                ->with('credit_dialog_open', true);
        }

        $amount = $this->paymentAvailabilityService->formatMinorUnits($result->appliedAmountMinor);
        $message = __('invoices.flash.credit_applied', ['amount' => $amount]);
        if ($invoice->fresh()->status === 'paid') {
            $message .= ' '.__('invoices.flash.credit_applied_paid');
        }

        return $this->mutationRedirect($invoice->fresh())->with('success', $message);
    }

    /** @return list<array{id: int, status: string, can_confirm: bool, can_cancel: bool}> */
    private function actionablePayments(Invoice $invoice): array
    {
        $canConfirm = Gate::allows(PermissionName::PaymentsConfirm->value);
        $canCancel = Gate::allows(PermissionName::PaymentsCancel->value);

        if (! $canConfirm && ! $canCancel) {
            return [];
        }

        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', $canCancel ? ['pending', 'confirmed'] : ['pending'])
            ->select(['id', 'invoice_id', 'status'])
            ->selectRaw(
                'CASE WHEN comment LIKE ? THEN 1 ELSE 0 END as is_legacy_credit_balance_payment',
                ['Автоматически применён Credit Balance%']
            )
            ->withExists([
                'creditBalanceEntries as has_exact_applied_credit_entry' => fn ($query) => $query
                    ->where('type', 'applied')
                    ->whereColumn('credit_balance_entries.invoice_id', 'payments.invoice_id'),
                'creditBalanceEntries as has_legacy_applied_credit_entry' => fn ($query) => $query
                    ->where('type', 'applied')
                    ->whereNull('invoice_id'),
            ])
            ->orderBy('id')
            ->get()
            ->map(function (Payment $payment) use ($canConfirm, $canCancel): array {
                $isAmbiguousLegacyCreditPayment = ! $payment->has_exact_applied_credit_entry
                    && ($payment->has_legacy_applied_credit_entry
                        || $payment->is_legacy_credit_balance_payment);

                return [
                    'id' => (int) $payment->id,
                    'status' => (string) $payment->status,
                    'can_confirm' => $canConfirm
                        && $payment->status === 'pending'
                        && Gate::allows('confirm', $payment),
                    'can_cancel' => $canCancel
                        && in_array($payment->status, ['pending', 'confirmed'], true)
                        && ! $isAmbiguousLegacyCreditPayment
                        && Gate::allows('cancel', $payment),
                ];
            })
            ->filter(fn (array $payment): bool => $payment['can_confirm'] || $payment['can_cancel'])
            ->values()
            ->all();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Invoice $invoice)
    {
        Gate::authorize('update', $invoice);

        $companyContext = $this->invoiceCompanyContext($request, $invoice);
        $invoice->loadMissing('payments:id,invoice_id,status');
        $editability = $this->editabilityService->evaluate($invoice);

        if (! $editability['editable']) {
            return $this->mutationRedirect($invoice, $companyContext['query'])
                ->with('error', $this->editabilityMessage($editability['reason']));
        }

        $invoice->load([
            'lines.subscription:id,payment_terms',
            'lines.order:id,payment_terms',
            'company',
            'contract',
            'issuerOrganization',
        ]);

        $invoice->loadMissing('issuerOrganization');
        return view('invoices.edit', compact('invoice', 'companyContext', 'editability'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        Invoice $invoice
    ) {
        Gate::authorize('update', $invoice);

        $companyContext = $this->invoiceCompanyContext($request, $invoice);
        $validated = $request->validate([
            'invoice_number' => ['nullable', 'string', 'max:50', 'unique:invoices,invoice_number,'.$invoice->id],
            'invoice_number_sequence' => ['nullable', 'integer', 'min:1'],
            'invoice_number_manual' => ['nullable', 'boolean'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
            'subtotal_amount' => ['prohibited'],
            'vat_enabled' => ['prohibited'],
            'vat_rate' => ['prohibited'],
            'vat_amount' => ['prohibited'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01'],
            'lines.*.subscription_id' => ['nullable', 'integer'],
            'lines.*.order_id' => ['nullable', 'integer'],
            'lines.*.period_start' => ['nullable', 'date'],
            'lines.*.period_end' => ['nullable', 'date'],
            'subscription_period_counts' => ['nullable', 'array'],
            'subscription_period_counts.*.subscription_id' => ['required', 'integer'],
            'subscription_period_counts.*.period_count' => ['required', 'integer', 'min:1', 'max:'.SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE],
        ]);
        $lines = array_values($validated['lines']);
        $subscriptionPeriodCounts = array_values($validated['subscription_period_counts'] ?? []);
        unset($validated['lines']);
        unset($validated['subscription_period_counts']);

        try {
            $this->updateInvoice->execute(
                $invoice,
                $validated,
                $lines,
                preserveSubjectAmounts: true,
                subscriptionPeriodCounts: $subscriptionPeriodCounts,
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (array_key_exists('invoice', $errors)) {
                return $this->mutationRedirect($invoice, $companyContext['query'])
                    ->with('error', (string) ($errors['invoice'][0] ?? __('invoices.errors.edit_forbidden')));
            }

            throw $exception;
        }

        return $this->mutationRedirect($invoice, $companyContext['query'])
            ->with('success', __('invoices.flash.updated'));
    }

    public function issue(
        Invoice $invoice,
        ApplyCreditToInvoice $applyCreditToInvoice
    ) {
        Gate::authorize('issue', $invoice);

        $actor = auth()->user();

        DB::transaction(function () use ($invoice, $applyCreditToInvoice, $actor) {
            /*
         * Блокируем инвойс, чтобы его нельзя было
         * выставить одновременно двумя запросами.
         */
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_draft_only'),
                ]);
            }

            if (
                $invoice->payments()
                    ->where('status', 'confirmed')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_confirmed_payments'),
                ]);
            }

            $contract = $invoice->contract;

            $lines = $invoice->lines()
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_no_lines'),
                ]);
            }

            /*
         * Блокируем все используемые подписки.
         */
            $subscriptionIds = $lines
                ->pluck('subscription_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $subscriptions = Subscription::query()
                ->whereIn('id', $subscriptionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $nextBillingDates = [];
            $occurrenceKeys = [];

            foreach ($lines->whereNotNull('subscription_id')->groupBy('subscription_id') as $subscriptionId => $group) {
                $subscription = $subscriptions->get($subscriptionId);
                if (! $subscription || (int) $subscription->contract_id !== (int) $invoice->contract_id) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_contract_mismatch')]);
                }
                if ($subscription->status !== 'active' || ! $subscription->next_billing_date) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_unavailable', ['description' => $group->first()->description])]);
                }

                $ordered = $group->sortBy([['period_start', 'asc'], ['id', 'asc']])->values();
                $expectedStart = CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();
                try {
                    $expected = $this->billingSchedule->occurrenceChain($subscription, $expectedStart, $ordered->count());
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_billing_invalid', ['description' => $group->first()->description])]);
                }

                foreach ($ordered as $index => $line) {
                    $occurrence = $expected[$index];
                    if (! $line->period_start || ! $line->period_end
                        || ! CarbonImmutable::parse($line->period_start)->startOfDay()->equalTo($occurrence['period_start'])
                        || ! CarbonImmutable::parse($line->period_end)->startOfDay()->equalTo($occurrence['period_end'])) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_schedule_invalid', ['description' => $line->description])]);
                    }
                    if ($occurrence['period_start']->lt(CarbonImmutable::parse($subscription->start_date)->startOfDay())
                        || $occurrence['period_start']->lt(CarbonImmutable::parse($contract->start_date)->startOfDay())
                        || ($contract->end_date && $occurrence['period_end']->gt(CarbonImmutable::parse($contract->end_date)->startOfDay()))) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_outside_term', ['description' => $line->description])]);
                    }
                    if ($line->billing_occurrence_key !== null && $line->billing_occurrence_key !== $occurrence['billing_occurrence_key']) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_key_invalid', ['description' => $line->description])]);
                    }
                    if (InvoiceLine::query()->where('billing_occurrence_key', $occurrence['billing_occurrence_key'])->where('invoice_id', '!=', $invoice->id)->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))->exists()) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.period_exists', ['description' => $line->description])]);
                    }
                    $occurrenceKeys[$line->id] = $occurrence['billing_occurrence_key'];
                }

                $nextBillingDates[$subscription->id] = $this->billingSchedule
                    ->nextOccurrenceStart($expected[count($expected) - 1]['period_start'], CarbonImmutable::parse($subscription->start_date)->startOfDay(), $this->billingSchedule->intervalFor($subscription))
                    ->toDateString();
            }

            $dueDate = $this->dueDateCalculator->calculate(
                issueDate: $invoice->issue_date,
                manualDueDate: $invoice->due_date,
                contractId: $invoice->contract_id,
                orderIds: $lines->pluck('order_id')->filter()->all(),
                subscriptionIds: $lines->pluck('subscription_id')->filter()->all()
            );

            /*
         * Только после всех проверок
         * меняем статус инвойса.
         */
            $invoice->update([
                'status' => 'issued',
                'due_date' => $dueDate,
            ]);

            foreach ($occurrenceKeys as $lineId => $key) {
                $lines->firstWhere('id', $lineId)?->update([
                    'billing_occurrence_key' => $key,
                ]);
            }

            /*
         * Переводим подписки на следующие периоды.
         */
            foreach ($nextBillingDates as $subscriptionId => $date) {
                $subscription = $subscriptions->get($subscriptionId);
                if ($subscription) {
                    $subscription->next_billing_date = $date;
                    $subscription->save();
                }
            }

            /*
         * Применяем кредитный баланс только после
         * успешного выставления черновика.
         */
            $applyCreditToInvoice->execute($invoice, actor: $actor);

            $this->activityRecorder->record(
                $contract
                    ? CompanyActivitySnapshot::companyFor($contract)
                    : CompanyActivitySnapshot::companyForInvoice($invoice),
                CompanyActivityEventType::InvoiceIssued,
                CompanyActivityCategory::Invoices,
                CompanyActivityVisibilityScope::Financials,
                subject: $invoice,
                metadata: CompanyActivitySnapshot::invoice($invoice, $contract),
                actor: $actor,
            );
        });

        $invoice->refresh();

        $message = $invoice->status === 'paid'
            ? __('invoices.flash.credit_applied_success')
            : __('invoices.flash.issued');

        return $this->mutationRedirect($invoice)
            ->with('success', $message);
    }

    public function cancel(Invoice $invoice)
    {
        Gate::authorize('cancel', $invoice);

        $actor = auth()->user();

        DB::transaction(function () use ($invoice, $actor) {
            /*
         * Блокируем инвойс, чтобы два запроса
         * не могли отменить его одновременно.
         */
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages([
                    'cancel' => __('invoices.errors.cancel_issued_only'),
                ]);
            }

            /*
         * Пока отмену инвойсов с платежами запрещаем.
         * Возврат денег и Credit Balance сделаем
         * отдельной контролируемой операцией.
         */
            if ($invoice->payments()
                ->where('status', 'confirmed')
                ->exists()) {
                throw ValidationException::withMessages([
                    'cancel' => __('invoices.errors.cancel_confirmed_payment'),
                ]);
            }

            $lines = $invoice->lines()
                ->lockForUpdate()
                ->get();

            $subscriptionLines = $lines
                ->whereNotNull('subscription_id');

            $subscriptionIds = $subscriptionLines
                ->pluck('subscription_id')
                ->unique()
                ->sort()
                ->values();

            $subscriptions = Subscription::query()
                ->whereIn('id', $subscriptionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /*
         * Сначала выполняем все проверки.
         * Только затем меняем статус и даты.
         */
            $rollbackDates = [];

            foreach ($subscriptionLines->groupBy('subscription_id') as $subscriptionId => $group) {
                $subscription = $subscriptions->get($subscriptionId);
                if (! $subscription) {
                    throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_missing')]);
                }

                $ordered = $group->sortBy([['period_start', 'asc'], ['id', 'asc']])->values();
                $first = $ordered->first();
                if (! $first?->period_start || ! $subscription->start_date) {
                    throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_period_missing')]);
                }

                try {
                    $expected = $this->billingSchedule->occurrenceChain(
                        $subscription,
                        CarbonImmutable::parse($first->period_start)->startOfDay(),
                        $ordered->count(),
                    );
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_interval_invalid', ['description' => $first->description])]);
                }

                foreach ($ordered as $index => $line) {
                    $occurrence = $expected[$index];
                    $isLegacySingleOccurrence = $ordered->count() === 1 && $line->billing_occurrence_key === null;
                    if (! $line->period_start || ! $line->period_end
                        || (! $isLegacySingleOccurrence && ! CarbonImmutable::parse($line->period_start)->startOfDay()->equalTo($occurrence['period_start']))
                        || (! $isLegacySingleOccurrence && ! CarbonImmutable::parse($line->period_end)->startOfDay()->equalTo($occurrence['period_end']))
                        || ($line->billing_occurrence_key !== null
                            && $line->billing_occurrence_key !== $occurrence['billing_occurrence_key'])) {
                        throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_sequence_invalid', ['description' => $line->description])]);
                    }
                }

                $last = $expected[count($expected) - 1];
                $expectedCurrentDate = $ordered->count() === 1 && $ordered->first()->billing_occurrence_key === null
                    ? CarbonImmutable::parse($ordered->first()->period_end)->addDay()->toDateString()
                    : $this->billingSchedule->nextOccurrenceStart(
                        $last['period_start'],
                        CarbonImmutable::parse($subscription->start_date)->startOfDay(),
                        $this->billingSchedule->intervalFor($subscription),
                    )->toDateString();
                if ($subscription->next_billing_date?->toDateString() !== $expectedCurrentDate) {
                    throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_date_changed', ['description' => $first->description])]);
                }

                if (InvoiceLine::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('invoice_id', '!=', $invoice->id)
                    ->whereDate('period_start', '>', $last['period_start']->toDateString())
                    ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->exists()) {
                    throw ValidationException::withMessages(['cancel' => __('invoices.errors.subscription_later_invoice', ['description' => $first->description])]);
                }

                $rollbackDates[$subscription->id] = $expected[0]['period_start']->toDateString();
            }

            /*
         * Инвойс не удаляется — остаётся
         * в бухгалтерской истории.
         */
            $invoice->update([
                'status' => 'cancelled',
            ]);

            foreach ($subscriptionLines as $line) {
                $line->update(['billing_occurrence_key' => null]);
            }

            /*
         * Возвращаем стандартные подписки
         * на начало отменённого периода.
         */
            foreach ($rollbackDates as $subscriptionId => $date) {
                $subscription = $subscriptions->get(
                    $subscriptionId
                );

                if (! $subscription) {
                    continue;
                }

                $subscription->next_billing_date = $date;
                $subscription->save();
            }

            $this->activityRecorder->record(
                $invoice->contract
                    ? CompanyActivitySnapshot::companyFor($invoice->contract)
                    : CompanyActivitySnapshot::companyForInvoice($invoice),
                CompanyActivityEventType::InvoiceCancelled,
                CompanyActivityCategory::Invoices,
                CompanyActivityVisibilityScope::Financials,
                subject: $invoice,
                metadata: CompanyActivitySnapshot::invoice($invoice, $invoice->contract),
                actor: $actor,
            );
        });

        return $this->mutationRedirect($invoice)
            ->with(
                'success',
                __('invoices.flash.cancelled')
            );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Invoice $invoice, DeleteInvoice $deleteInvoice)
    {
        Gate::authorize('delete', $invoice);

        // Resolve the authorized Company destination before the invoice is removed.
        $companyContext = $this->invoiceCompanyContext($request, $invoice);
        $companyRedirect = $this->authorizedCompanyRedirect($companyContext, $invoice);

        try {
            $deleteInvoice->execute($invoice, auth()->user());
        } catch (InvoiceDeletionException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }

        $redirect = $companyRedirect ?? (Gate::allows('viewAny', Invoice::class)
            ? redirect()->route('invoices.index')
            : redirect()->to($this->landingUrl()));

        return $redirect
            ->with(
                'success',
                __('invoices.flash.deleted')
            );
    }

    /** @param array<string, string> $invoiceShowQuery */
    private function mutationRedirect(
        Invoice $invoice,
        array $invoiceShowQuery = []
    ): RedirectResponse {
        if (Gate::allows('view', $invoice)) {
            return redirect()->route('invoices.show', [
                'invoice' => $invoice,
                ...$invoiceShowQuery,
            ]);
        }

        $invoice->loadMissing('company:id,name');

        if (Gate::allows('view', $invoice->company)) {
            return redirect()->route('companies.show', $invoice->company);
        }

        return redirect()->to($this->landingUrl());
    }

    private function landingUrl(): string
    {
        return app(AuthorizedLandingPage::class)->url(auth()->user());
    }

    /**
     * AJAX — контракты компании для формы инвойса
     */
    public function getContracts(Company $company, ActiveOrganizationContext $organizationContext)
    {
        $contracts = $company->contracts()
            ->where('status', 'active')
            ->when($organizationContext->resolve(), fn ($query, $organization) => $query
                ->where('issuer_organization_id', $organization->getKey()))
            ->when(! $organizationContext->resolve(), fn ($query) => $query->whereRaw('1 = 0'))
            ->get(['id', 'contract_number', 'start_date', 'end_date']);

        return response()->json($contracts);
    }

    /**
     * AJAX — заказы и подписки контракта для формы инвойса
     */
    public function getContractItems(Contract $contract)
    {
        $contract->loadMissing('company');

        $orders = $contract->orders()
            ->where('status', '!=', 'cancelled')
            ->with('serviceType')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'type' => 'order',

                    'description' => $order->title
                        ?: $order->serviceType?->name
                        ?: __('invoices.form.one_time'),

                    'amount' => (float) $order->price,
                    'payment_terms' => $order->payment_terms,
                ];
            })
            ->values();

        $subscriptions = $contract->subscriptions()
            ->where('status', 'active')
            ->with('serviceType')
            ->get()
            ->map(function ($subscription) use ($contract) {
                $preview = $this->subscriptionOccurrencePreview($subscription, $contract);

                return [
                    'id' => $subscription->id,
                    'type' => 'subscription',

                    'description' => $subscription->title
                        ?: $subscription->serviceType?->name
                        ?: __('invoices.form.subscription'),

                    'amount' => (float) $subscription->amount,

                    'billing_period' => $subscription->billing_period,
                    'custom_interval_value' => $subscription->custom_interval_value,
                    'custom_interval_unit' => $subscription->custom_interval_unit,

                    'start_date' => $subscription->start_date
                        ? Carbon::parse($subscription->start_date)->format('Y-m-d')
                        : null,

                    'next_billing_date' => $subscription->next_billing_date
                        ? Carbon::parse($subscription->next_billing_date)->format('Y-m-d')
                        : null,

                    'available_occurrences' => $preview['occurrences'],
                    'selectable' => $preview['selectable'],
                    'unavailable_reason' => $preview['reason'],

                    'payment_terms' => $subscription->payment_terms,
                ];
            })
            ->values();

        return response()->json([
            'contract' => [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'start_date' => $contract->start_date?->format('Y-m-d'),
                'end_date' => $contract->end_date?->format('Y-m-d'),

                'company' => [
                    'id' => $contract->company->id,
                    'name' => $contract->company->name,
                    'voen' => $contract->company->voen,
                ],
            ],

            'orders' => $orders,
            'subscriptions' => $subscriptions,
        ]);
    }

    /** @return array{selectable: bool, reason: ?string, occurrences: list<array{period_start: string, period_end: string}>} */
    private function subscriptionOccurrencePreview(Subscription $subscription, Contract $contract): array
    {
        if (! $subscription->next_billing_date || ! $subscription->start_date) {
            return ['selectable' => false, 'reason' => __('invoices.form.billing_not_set'), 'occurrences' => []];
        }

        try {
            $chain = $this->billingSchedule->occurrenceChain(
                $subscription,
                CarbonImmutable::parse($subscription->next_billing_date)->startOfDay(),
                SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE,
            );
        } catch (\Throwable) {
            return ['selectable' => false, 'reason' => __('invoices.form.billing_interval_invalid'), 'occurrences' => []];
        }

        $contractEnd = $contract->end_date?->startOfDay();
        $occurrences = collect($chain)
            ->takeWhile(fn (array $occurrence): bool => $contractEnd === null || $occurrence['period_end']->lte($contractEnd))
            ->map(fn (array $occurrence): array => [
                'period_start' => $occurrence['period_start']->toDateString(),
                'period_end' => $occurrence['period_end']->toDateString(),
            ])
            ->values()
            ->all();

        return $occurrences === []
            ? ['selectable' => false, 'reason' => __('invoices.form.period_outside_contract'), 'occurrences' => []]
            : ['selectable' => true, 'reason' => null, 'occurrences' => $occurrences];
    }

    private function invoiceCompanyContext(Request $request, Invoice $invoice): array
    {
        $tab = in_array($request->input('tab'), ['activity', 'invoices', 'payments'], true)
            ? $request->input('tab')
            : 'invoices';

        return CompanyPageContext::resolve($request, $invoice->company, $tab);
    }

    private function authorizedCompanyRedirect(array $companyContext, Invoice $invoice): ?RedirectResponse
    {
        if (! $companyContext['active']) {
            return null;
        }

        $company = $invoice->company;
        if (! Gate::allows('view', $company) || ! Gate::allows('viewFinancials', $company)) {
            return null;
        }

        if ($companyContext['tab'] === 'invoices' && ! Gate::allows('viewAny', Invoice::class)) {
            return null;
        }

        return redirect()->route('companies.show', [
            'company' => $company,
            'tab' => $companyContext['tab'],
        ]);
    }

    private function editabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'confirmed_payment' => __('invoices.errors.confirmed_payment'),
            'cancelled' => __('invoices.errors.cancelled_edit'),
            default => __('invoices.errors.current_state_edit'),
        };
    }
}
