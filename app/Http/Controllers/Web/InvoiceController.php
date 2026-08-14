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
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\InvoiceDueDateCalculator;
use App\Services\InvoiceEditabilityService;
use App\Services\InvoicePaymentAvailabilityService;
use App\Services\InvoicePaymentBreakdownPresenter;
use App\Services\InvoicePaymentSourceResolver;
use App\Services\SubscriptionBillingSchedule;
use App\Support\Access\PermissionName;
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
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService,
        private readonly InvoicePaymentBreakdownPresenter $paymentBreakdownPresenter,
        private readonly InvoicePaymentSourceResolver $paymentSourceResolver,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly InvoiceSellerSnapshot $sellerSnapshot,
        private readonly UpdateInvoice $updateInvoice,
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

        $query = Invoice::query()
            ->with('company')
            ->withSum([
                'payments as confirmed_paid_amount' => function ($paymentQuery) {
                    $paymentQuery->where('status', 'confirmed');
                },
            ], 'amount');

        $this->paymentSourceResolver->addAggregates($query);

        $allowedStatuses = [
            'draft',
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

        $hasAllowedStatusFilter = in_array($request->input('status'), $allowedStatuses, true);
        $activeStatusFilter = $hasAllowedStatusFilter ? (string) $request->input('status') : '';
        if ($hasAllowedStatusFilter) {
            $query->where('status', $request->input('status'));
        }

        if ($activeCompanyId !== null) {
            $query->where('invoices.company_id', $activeCompanyId);
        }

        if ($activeContractId !== null) {
            $query->where('invoices.contract_id', $activeContractId);
        }

        $activeOverdue = $request->boolean('overdue');
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
        if ($hasAllowedStatusFilter) {
            $paginationParameters['status'] = $activeStatusFilter;
        }
        if ($activeOverdue) {
            $paginationParameters['overdue'] = 1;
        }
        if (! $hasAllowedStatusFilter) {
            unset($paginationParameters['status']);
        }
        $paginationParameters['sort'] = $sort;
        $paginationParameters['direction'] = $direction;

        $invoices = $query
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

        return view('invoices.index', compact(
            'invoices',
            'companies',
            'contracts',
            'invoicePaymentSources',
            'activeStatusFilter',
            'search',
            'activeCompanyId',
            'activeContractId',
            'activeOverdue'
        ));
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
    public function create()
    {
        Gate::authorize('create', Invoice::class);

        $companies = Company::where('status', 'active')
            ->orderBy('name')
            ->get();
        $backUrl = Gate::allows('viewAny', Invoice::class)
            ? route('invoices.index')
            : $this->landingUrl();

        return view('invoices.create', compact('companies', 'backUrl'));
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
            'invoice_number' => 'required|string|max:50|unique:invoices,invoice_number',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
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
                        $fail('Выберите предмет договора для позиции инвойса.');
                    }
                },
            ],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'lines.*.order_id' => ['nullable', 'exists:orders,id'],
            'lines.*.period_start' => ['nullable', 'date'],
            'lines.*.period_end' => ['nullable', 'date'],
        ]);

        $company = Company::findOrFail($validated['company_id']);
        $contract = Contract::query()
            ->whereKey($validated['contract_id'])
            ->where('company_id', $company->id)
            ->first();

        if (! $contract) {
            return back()
                ->withErrors([
                    'contract_id' => 'Выбранный договор не принадлежит выбранной компании.',
                ])
                ->withInput();
        }

        $invoice = $this->createInvoice->execute(
            $company,
            $contract,
            $validated,
            array_values($validated['lines']),
            canonicalizeSubjectAmounts: true,
        );

        return $this->mutationRedirect($invoice)
            ->with('success', 'Черновик инвойса успешно сохранён.');
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
        $paymentAvailability = $this->paymentAvailabilityService->evaluate($invoice);
        $editability = $this->editabilityService->evaluate($invoice);
        $hasPayments = $invoice->payments->isNotEmpty();
        $actionablePayments = $this->actionablePayments($invoice);

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
            'paymentAvailability',
            'editability',
            'hasPayments',
            'actionablePayments'
        );
        $viewData['sellerFallback'] = $this->sellerSnapshot->legacyFallback();

        if ($canViewPaymentHistory) {
            $viewData += compact('paymentsById', 'paymentSource');
        }

        return view('invoices.show', $viewData);
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
        ]);

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
            'invoice_number' => [
                'required',
                'string',
                'max:50',
                'unique:invoices,invoice_number,'.$invoice->id,
            ],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01'],
            'lines.*.subscription_id' => ['nullable', 'integer'],
            'lines.*.order_id' => ['nullable', 'integer'],
            'lines.*.period_start' => ['nullable', 'date'],
            'lines.*.period_end' => ['nullable', 'date'],
        ]);
        $lines = array_values($validated['lines']);
        unset($validated['lines']);

        try {
            $this->updateInvoice->execute(
                $invoice,
                $validated,
                $lines,
                preserveSubjectAmounts: true,
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (array_key_exists('invoice', $errors)) {
                return $this->mutationRedirect($invoice, $companyContext['query'])
                    ->with('error', (string) ($errors['invoice'][0] ?? 'Инвойс нельзя изменить.'));
            }

            throw $exception;
        }

        return $this->mutationRedirect($invoice, $companyContext['query'])
            ->with('success', 'Инвойс успешно обновлён.');
    }

    public function issue(
        Invoice $invoice,
        ApplyCreditToInvoice $applyCreditToInvoice
    ) {
        Gate::authorize('issue', $invoice);

        DB::transaction(function () use ($invoice, $applyCreditToInvoice) {
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
                    'issue' => 'Выставить можно только черновик инвойса.',
                ]);
            }

            if (
                $invoice->payments()
                    ->where('status', 'confirmed')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'issue' => 'Нельзя выставить черновик с подтверждёнными платежами.',
                ]);
            }

            $contract = $invoice->contract;

            if (! $contract) {
                throw ValidationException::withMessages([
                    'issue' => 'Инвойс не связан с договором.',
                ]);
            }

            $lines = $invoice->lines()
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'issue' => 'В инвойсе должна быть хотя бы одна позиция.',
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
            $seenSubscriptions = [];

            foreach ($lines as $index => $line) {
                /*
             * Разовые и ручные позиции
             * не изменяют next_billing_date.
             */
                if (! $line->subscription_id) {
                    continue;
                }

                if (isset($seenSubscriptions[$line->subscription_id])) {
                    throw ValidationException::withMessages([
                        'issue' => 'Одна подписка не может быть добавлена в инвойс несколько раз.',
                    ]);
                }

                $seenSubscriptions[$line->subscription_id] = true;

                $subscription = $subscriptions->get(
                    $line->subscription_id
                );

                if (! $subscription) {
                    throw ValidationException::withMessages([
                        'issue' => 'Одна из подписок больше не существует.',
                    ]);
                }

                if (
                    (int) $subscription->contract_id
                    !== (int) $invoice->contract_id
                ) {
                    throw ValidationException::withMessages([
                        'issue' => 'Одна из подписок не принадлежит договору инвойса.',
                    ]);
                }

                if ($subscription->status !== 'active') {
                    throw ValidationException::withMessages([
                        'issue' => "Подписка «{$line->description}» больше не активна.",
                    ]);
                }

                if (! $line->period_start || ! $line->period_end) {
                    throw ValidationException::withMessages([
                        'issue' => "У позиции «{$line->description}» не указан расчётный период.",
                    ]);
                }

                $periodStart = Carbon::parse(
                    $line->period_start
                )->startOfDay();

                $periodEnd = Carbon::parse(
                    $line->period_end
                )->startOfDay();

                if ($periodEnd->lt($periodStart)) {
                    throw ValidationException::withMessages([
                        'issue' => "У позиции «{$line->description}» неверный расчётный период.",
                    ]);
                }

                $subscriptionStart = Carbon::parse(
                    $subscription->start_date
                )->startOfDay();

                if ($periodStart->lt($subscriptionStart)) {
                    throw ValidationException::withMessages([
                        'issue' => "Период позиции «{$line->description}» начинается раньше подписки.",
                    ]);
                }

                $contractStart = Carbon::parse(
                    $contract->start_date
                )->startOfDay();

                if ($periodStart->lt($contractStart)) {
                    throw ValidationException::withMessages([
                        'issue' => "Период позиции «{$line->description}» начинается раньше договора.",
                    ]);
                }

                if ($contract->end_date) {
                    $contractEnd = Carbon::parse(
                        $contract->end_date
                    )->startOfDay();

                    if ($periodEnd->gt($contractEnd)) {
                        throw ValidationException::withMessages([
                            'issue' => "Период позиции «{$line->description}» выходит за срок договора.",
                        ]);
                    }
                }

                /*
             * Проверяем, что другой non-cancelled инвойс
             * не содержит reservation этой подписки за тот же период.
             */
                $periodAlreadyInvoiced = InvoiceLine::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('invoice_id', '!=', $invoice->id)
                    ->whereDate(
                        'period_start',
                        $periodStart->toDateString()
                    )
                    ->whereDate(
                        'period_end',
                        $periodEnd->toDateString()
                    )
                    ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->exists();

                if ($periodAlreadyInvoiced) {
                    throw ValidationException::withMessages([
                        'issue' => "По подписке «{$line->description}» уже есть инвойс за этот период.",
                    ]);
                }

                try {
                    $interval = $this->billingSchedule->intervalFor($subscription);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        'issue' => "У подписки «{$line->description}» не заполнен корректный интервал биллинга.",
                    ]);
                }

                if (! $subscription->next_billing_date) {
                    throw ValidationException::withMessages([
                        'issue' => "У подписки «{$line->description}» не указана следующая дата выставления.",
                    ]);
                }

                $expectedPeriodStart = CarbonImmutable::parse(
                    $subscription->next_billing_date
                )->startOfDay();

                $anchorDate = CarbonImmutable::parse($subscription->start_date)->startOfDay();
                $expectedPeriodEnd = $this->billingSchedule->periodEnd(
                    $expectedPeriodStart,
                    $anchorDate,
                    $interval,
                );

                if (! $periodStart->equalTo($expectedPeriodStart)) {
                    throw ValidationException::withMessages([
                        'issue' => "Начало периода позиции «{$line->description}» больше не соответствует графику подписки.",
                    ]);
                }

                if (! $periodEnd->equalTo($expectedPeriodEnd)) {
                    throw ValidationException::withMessages([
                        'issue' => "Окончание периода позиции «{$line->description}» не соответствует графику подписки.",
                    ]);
                }

                $expectedKey = $this->billingSchedule->occurrenceKey(
                    (int) $subscription->id,
                    $expectedPeriodStart,
                    $expectedPeriodEnd,
                );
                if ($line->billing_occurrence_key !== null
                    && $line->billing_occurrence_key !== $expectedKey) {
                    throw ValidationException::withMessages([
                        'issue' => "Occurrence key позиции «{$line->description}» не соответствует её периоду.",
                    ]);
                }

                $occurrenceKeys[$line->id] = $expectedKey;
                $nextBillingDates[$subscription->id] = $this->billingSchedule
                    ->nextOccurrenceStart($expectedPeriodStart, $anchorDate, $interval)
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
            $applyCreditToInvoice->execute($invoice);
        });

        $invoice->refresh();

        $message = $invoice->status === 'paid'
            ? 'Инвойс выставлен и полностью оплачен кредитным балансом.'
            : 'Инвойс успешно выставлен.';

        return $this->mutationRedirect($invoice)
            ->with('success', $message);
    }

    public function cancel(Invoice $invoice)
    {
        Gate::authorize('cancel', $invoice);

        DB::transaction(function () use ($invoice) {
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
                    'cancel' => 'Отменить можно только выставленный инвойс без оплат.',
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
                    'cancel' => 'Нельзя отменить инвойс, по которому есть подтверждённый платёж.',
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

            foreach ($subscriptionLines->groupBy('subscription_id') as $subscriptionId => $lines) {
                $subscription = $subscriptions->get(
                    $subscriptionId
                );

                if (! $subscription) {
                    throw ValidationException::withMessages([
                        'cancel' => 'Одна из подписок больше не существует.',
                    ]);
                }

                if ($lines->count() > 1) {
                    throw ValidationException::withMessages([
                        'cancel' => 'Нельзя автоматически восстановить график: '
                            .'инвойс содержит несколько периодов одной подписки.',
                    ]);
                }

                $line = $lines->first();

                if (! $line->period_start || ! $line->period_end) {
                    throw ValidationException::withMessages([
                        'cancel' => "У позиции «{$line->description}» отсутствует расчётный период.",
                    ]);
                }

                /*
                 * Если после отменяемого периода уже существует
                 * другая non-cancelled reservation, откатывать дату нельзя.
             */
                $hasLaterPeriod = InvoiceLine::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('invoice_id', '!=', $invoice->id)
                    ->whereDate(
                        'period_start',
                        '>',
                        Carbon::parse($line->period_start)
                            ->toDateString()
                    )
                    ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->exists();

                if ($hasLaterPeriod) {
                    throw ValidationException::withMessages([
                        'cancel' => "Нельзя отменить позицию «{$line->description}»: "
                            .'по подписке уже существует более поздний выставленный инвойс.',
                    ]);
                }

                /*
                 * issue() устанавливает дату на следующий день после period_end.
                 * Если дата уже изменена, отмена не должна затирать чужое изменение.
                 */
                $expectedCurrentDate = Carbon::parse($line->period_end)
                    ->addDay()
                    ->toDateString();

                $currentDate = $subscription->next_billing_date
                    ? Carbon::parse($subscription->next_billing_date)->toDateString()
                    : null;

                if ($currentDate !== $expectedCurrentDate) {
                    throw ValidationException::withMessages([
                        'cancel' => "Нельзя восстановить график подписки «{$line->description}»: "
                            .'следующая дата выставления уже была изменена.',
                    ]);
                }

                $rollbackDates[$subscription->id] = Carbon::parse(
                    $line->period_start
                )->toDateString();
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
        });

        return $this->mutationRedirect($invoice)
            ->with(
                'success',
                'Инвойс успешно отменён.'
            );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice, DeleteInvoice $deleteInvoice)
    {
        Gate::authorize('delete', $invoice);

        try {
            $deleteInvoice->execute($invoice);
        } catch (InvoiceDeletionException $exception) {
            return back()->withErrors(['delete' => $exception->getMessage()]);
        }

        $redirect = Gate::allows('viewAny', Invoice::class)
            ? redirect()->route('invoices.index')
            : redirect()->to($this->landingUrl());

        return $redirect
            ->with(
                'success',
                'Черновик инвойса успешно удалён.'
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
                        ?: 'Разовая услуга',

                    'amount' => (float) $order->price,
                    'payment_terms' => $order->payment_terms,
                ];
            })
            ->values();

        $subscriptions = $contract->subscriptions()
            ->where('status', 'active')
            ->with('serviceType')
            ->get()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'type' => 'subscription',

                    'description' => $subscription->title
                        ?: $subscription->serviceType?->name
                        ?: 'Подписка',

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

    private function invoiceCompanyContext(Request $request, Invoice $invoice): array
    {
        $tab = $request->input('tab') === 'payments' ? 'payments' : 'invoices';

        return CompanyPageContext::resolve($request, $invoice->company, $tab);
    }

    private function editabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'confirmed_payment' => 'Инвойс уже получил оплату и больше не может быть изменён.',
            'cancelled' => 'Отменённый инвойс нельзя редактировать.',
            default => 'Инвойс в текущем состоянии нельзя редактировать.',
        };
    }
}
