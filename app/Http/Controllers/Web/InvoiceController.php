<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Contract;
use Illuminate\Support\Carbon;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Validation\ValidationException;

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
            $query->where(function ($q) use ($search) {
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
            'company_id' => 'required|exists:companies,id',
            'contract_id' => 'required|exists:contracts,id',

            'invoice_number' => 'required|string|max:50|unique:invoices,invoice_number',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',

            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',

            'status' => [
                'required',
                'in:draft,issued',
            ],

            'seller_name' => 'nullable|string|max:255',
            'seller_voen' => 'nullable|string|max:20',
            'seller_bank_name' => 'nullable|string|max:255',
            'seller_iban' => 'nullable|string|max:50',
            'seller_bank_code' => 'nullable|string|max:20',
            'seller_bank_voen' => 'nullable|string|max:20',
            'seller_swift' => 'nullable|string|max:20',

            'payer_name' => 'nullable|string|max:255',
            'payer_voen' => 'nullable|string|max:20',
            'contract_reference' => 'nullable|string|max:50',

            'comment' => 'nullable|string',

            'lines' => ['required', 'array', 'min:1'],

            'lines.*.description' => [
                'required',
                'string',
                'max:255',
            ],

            'lines.*.amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'lines.*.subscription_id' => [
                'nullable',
                'exists:subscriptions,id',
            ],

            'lines.*.order_id' => [
                'nullable',
                'exists:orders,id',
            ],

            'lines.*.period_start' => [
                'nullable',
                'date',
            ],

            'lines.*.period_end' => [
                'nullable',
                'date',
            ],
        ]);

        $company = Company::findOrFail($validated['company_id']);

        $contract = Contract::query()
            ->whereKey($validated['contract_id'])
            ->where('company_id', $company->id)
            ->first();

        if (!$contract) {
            return back()
                ->withErrors([
                    'contract_id' => 'Выбранный договор не принадлежит выбранной компании.',
                ])
                ->withInput();
        }

        $lineErrors = [];
        $requestPeriodKeys = [];

        foreach ($validated['lines'] as $index => $line) {
            $fieldPrefix = "lines.{$index}";

            $orderId = $line['order_id'] ?? null;
            $subscriptionId = $line['subscription_id'] ?? null;

            $periodStartValue = $line['period_start'] ?? null;
            $periodEndValue = $line['period_end'] ?? null;

            /*
     * Одна строка не может одновременно ссылаться
     * и на заказ, и на подписку.
     */
            if ($orderId && $subscriptionId) {
                $lineErrors["{$fieldPrefix}.description"] =
                    'Позиция не может одновременно быть заказом и подпиской.';

                continue;
            }

            /*
     * Разовые услуги и ручные строки
     * не должны иметь расчётного периода.
     */
            if (!$subscriptionId && ($periodStartValue || $periodEndValue)) {
                $lineErrors["{$fieldPrefix}.period_start"] =
                    'Расчётный период разрешён только для подписок.';
            }

            /*
     * Проверяем разовую услугу.
     */
            if ($orderId) {
                $orderExists = Order::query()
                    ->whereKey($orderId)
                    ->where('contract_id', $contract->id)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if (!$orderExists) {
                    $lineErrors["{$fieldPrefix}.order_id"] =
                        'Выбранная разовая услуга не принадлежит этому договору или отменена.';
                }

                continue;
            }

            /*
     * Ручная строка без order_id и subscription_id разрешена.
     */
            if (!$subscriptionId) {
                continue;
            }

            /*
     * Подписка должна принадлежать выбранному договору
     * и быть активной.
     */
            $subscription = Subscription::query()
                ->whereKey($subscriptionId)
                ->where('contract_id', $contract->id)
                ->first();

            if (!$subscription) {
                $lineErrors["{$fieldPrefix}.subscription_id"] =
                    'Выбранная подписка не принадлежит этому договору.';

                continue;
            }

            if ($subscription->status !== 'active') {
                $lineErrors["{$fieldPrefix}.subscription_id"] =
                    'Счёт можно выставить только по активной подписке.';

                continue;
            }

            /*
     * Для подписки обе даты обязательны.
     */
            if (!$periodStartValue || !$periodEndValue) {
                $lineErrors["{$fieldPrefix}.period_start"] =
                    'Для подписки необходимо указать начало и окончание расчётного периода.';

                continue;
            }

            $periodStart = Carbon::parse($periodStartValue)
                ->startOfDay();

            $periodEnd = Carbon::parse($periodEndValue)
                ->startOfDay();

            if ($periodEnd->lt($periodStart)) {
                $lineErrors["{$fieldPrefix}.period_end"] =
                    'Дата окончания периода не может быть раньше даты начала.';

                continue;
            }

            $subscriptionStart = Carbon::parse(
                $subscription->start_date
            )->startOfDay();

            $contractStart = Carbon::parse(
                $contract->start_date
            )->startOfDay();

            $contractEnd = $contract->end_date
                ? Carbon::parse($contract->end_date)->startOfDay()
                : null;

            /*
     * Период не может начаться раньше подписки.
     */
            if ($periodStart->lt($subscriptionStart)) {
                $lineErrors["{$fieldPrefix}.period_start"] =
                    'Расчётный период не может начинаться раньше подписки.';
            }

            /*
     * Период должен находиться внутри договора.
     */
            if ($periodStart->lt($contractStart)) {
                $lineErrors["{$fieldPrefix}.period_start"] =
                    'Расчётный период не может начинаться раньше договора.';
            }

            if ($contractEnd && $periodEnd->gt($contractEnd)) {
                $lineErrors["{$fieldPrefix}.period_end"] =
                    'Расчётный период не может выходить за дату окончания договора.';
            }

            /*
     * Для стандартного графика сервер самостоятельно
     * рассчитывает ожидаемые даты.
     */
            $monthsByBillingPeriod = [
                'monthly' => 1,
                'quarterly' => 3,
                'semiannual' => 6,
                'annual' => 12,
            ];

            if (isset(
                $monthsByBillingPeriod[$subscription->billing_period]
            )) {
                $expectedPeriodStart = Carbon::parse(
                    $subscription->next_billing_date
                )->startOfDay();

                $expectedPeriodEnd = $expectedPeriodStart
                    ->copy()
                    ->addMonthsNoOverflow(
                        $monthsByBillingPeriod[$subscription->billing_period]
                    )
                    ->subDay();

                if (!$periodStart->equalTo($expectedPeriodStart)) {
                    $lineErrors["{$fieldPrefix}.period_start"] =
                        'Начало периода не соответствует следующей дате выставления подписки.';
                }

                if (!$periodEnd->equalTo($expectedPeriodEnd)) {
                    $lineErrors["{$fieldPrefix}.period_end"] =
                        'Окончание периода не соответствует графику подписки.';
                }
            }

            /*
     * Не допускаем одинаковую подписку с одинаковым
     * периодом дважды в одной форме.
     */
            $requestPeriodKey = implode(':', [
                $subscription->id,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            ]);

            if (isset($requestPeriodKeys[$requestPeriodKey])) {
                $lineErrors["{$fieldPrefix}.subscription_id"] =
                    'Эта подписка уже добавлена за указанный период.';
            }

            $requestPeriodKeys[$requestPeriodKey] = true;

            /*
     * Не допускаем повторное выставление подписки
     * за тот же период в другом неотменённом инвойсе.
     */
            $periodAlreadyInvoiced = InvoiceLine::query()
                ->where('subscription_id', $subscription->id)
                ->whereDate(
                    'period_start',
                    $periodStart->toDateString()
                )
                ->whereDate(
                    'period_end',
                    $periodEnd->toDateString()
                )
                ->whereHas('invoice', function ($query) {
                    $query->where('status', '!=', 'cancelled');
                })
                ->exists();

            if ($periodAlreadyInvoiced) {
                $lineErrors["{$fieldPrefix}.period_start"] =
                    'По этой подписке уже существует инвойс за указанный период.';
            }
        }

        if (!empty($lineErrors)) {
            $firstError = array_values($lineErrors)[0];

            throw ValidationException::withMessages(
                array_merge(
                    [
                        'lines' => $firstError,
                    ],
                    $lineErrors
                )
            );
        }

        $invoice = DB::transaction(function () use (
            $validated,
            $company,
            $contract
        ) {
            $lines = $validated['lines'];

            $totalAmount = collect($lines)->sum('amount');

            $invoiceData = collect($validated)
                ->except('lines')
                ->toArray();

            $invoiceData['total_amount'] = $totalAmount;

            /*
         * Сохраняем снимок реквизитов на момент выставления.
         * Даже если компания или номер договора позже изменятся,
         * старый инвойс сохранит первоначальные данные.
         */
            $invoiceData['payer_name'] = $company->name;
            $invoiceData['payer_voen'] = $company->voen;
            $invoiceData['contract_reference'] = $contract->contract_number;

            $invoice = Invoice::create($invoiceData);

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'amount' => $line['amount'],

                    'subscription_id' =>
                    $line['subscription_id'] ?? null,

                    'order_id' =>
                    $line['order_id'] ?? null,

                    'period_start' =>
                    $line['period_start'] ?? null,

                    'period_end' =>
                    $line['period_end'] ?? null,
                ]);
            }

            /*
 * После выставления счёта переводим подписки
 * на следующий расчётный период.
 *
 * Черновик график подписки не изменяет.
 */
            if ($invoice->status === 'issued') {
                foreach ($lines as $line) {
                    $subscriptionId =
                        $line['subscription_id'] ?? null;

                    if (!$subscriptionId) {
                        continue;
                    }

                    $subscription = Subscription::query()
                        ->lockForUpdate()
                        ->find($subscriptionId);

                    if (!$subscription) {
                        continue;
                    }

                    /*
         * Для собственного графика следующую дату
         * пока не рассчитываем автоматически.
         */
                    if ($subscription->billing_period === 'custom') {
                        continue;
                    }

                    if (empty($line['period_end'])) {
                        continue;
                    }

                    $nextBillingDate = Carbon::parse(
                        $line['period_end']
                    )
                        ->addDay()
                        ->toDateString();

                    $subscription->update([
                        'next_billing_date' => $nextBillingDate,
                    ]);
                }
            }

            /*
            * Кредитный баланс применяется только
            * к выставленному счёту.
            *
            * Черновик не должен резервировать или списывать деньги.
            */
            if ($invoice->status === 'issued') {
                $creditBalance = $company->creditBalance;

                if ($creditBalance && $creditBalance->amount > 0) {
                    $applied = $creditBalance->apply(
                        $invoice->total_amount,
                        $invoice
                    );

                    if ($applied > 0) {
                        $invoice->payments()->create([
                            'company_id' => $company->id,
                            'payment_date' => now()->toDateString(),
                            'amount' => $applied,
                            'payment_method' => 'transfer',
                            'status' => 'confirmed',
                            'comment' =>
                            "Автоматически применён Credit Balance ({$applied} ₼)",
                        ]);
                    }
                }
            }

            return $invoice;
        });

        $message = $invoice->status === 'draft'
            ? 'Черновик инвойса успешно сохранён.'
            : 'Инвойс успешно выставлен.';

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', $message);
    }

    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        $invoice->load([
            'company',
            'contract',
            'lines',
            'payments' => fn($query) => $query
                ->orderByDesc('payment_date'),
        ]);

        return view('invoices.show', compact('invoice'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Редактировать можно только черновик инвойса.'
                );
        }

        if (
            $invoice->payments()
            ->where('status', 'confirmed')
            ->exists()
        ) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Нельзя редактировать инвойс с подтверждёнными платежами.'
                );
        }

        $invoice->load([
            'lines',
            'company',
            'contract',
        ]);

        $companies = Company::query()
            ->whereKey($invoice->company_id)
            ->get();

        return view(
            'invoices.edit',
            compact('invoice', 'companies')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        Invoice $invoice
    ) {
        if ($invoice->status !== 'draft') {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Изменять можно только черновик инвойса.'
                );
        }

        if (
            $invoice->payments()
            ->where('status', 'confirmed')
            ->exists()
        ) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Нельзя изменять инвойс с подтверждёнными платежами.'
                );
        }

        $validated = $request->validate([
            'company_id' => [
                'required',
                'exists:companies,id',
            ],

            'invoice_number' => [
                'required',
                'string',
                'max:50',
                'unique:invoices,invoice_number,' . $invoice->id,
            ],

            'issue_date' => [
                'required',
                'date',
            ],

            'due_date' => [
                'required',
                'date',
                'after_or_equal:issue_date',
            ],

            'period_start' => [
                'nullable',
                'date',
            ],

            'period_end' => [
                'nullable',
                'date',
                'after_or_equal:period_start',
            ],

            /*
         * Через обычное редактирование статус
         * пока остаётся только draft.
         */
            'status' => [
                'required',
                'in:draft',
            ],

            'seller_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'seller_voen' => [
                'nullable',
                'string',
                'max:20',
            ],

            'seller_bank_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'seller_iban' => [
                'nullable',
                'string',
                'max:50',
            ],

            'seller_bank_code' => [
                'nullable',
                'string',
                'max:20',
            ],

            'seller_bank_voen' => [
                'nullable',
                'string',
                'max:20',
            ],

            'seller_swift' => [
                'nullable',
                'string',
                'max:20',
            ],

            'payer_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'payer_voen' => [
                'nullable',
                'string',
                'max:20',
            ],

            'contract_reference' => [
                'nullable',
                'string',
                'max:50',
            ],

            'comment' => [
                'nullable',
                'string',
            ],

            'lines' => [
                'required',
                'array',
                'min:1',
            ],

            'lines.*.id' => [
                'nullable',
                'integer',
            ],

            'lines.*.description' => [
                'required',
                'string',
                'max:255',
            ],

            'lines.*.amount' => [
                'required',
                'numeric',
                'min:0.01',
            ],
        ]);

        /*
     * Компания черновика не должна меняться,
     * потому что инвойс уже связан с договором.
     */
        if (
            (int) $validated['company_id']
            !== (int) $invoice->company_id
        ) {
            throw ValidationException::withMessages([
                'company_id' =>
                'Нельзя изменить компанию существующего инвойса.',
            ]);
        }

        DB::transaction(function () use (
            $invoice,
            $validated
        ) {
            $originalLines = $invoice->lines()
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $submittedExistingIds = [];

            foreach ($validated['lines'] as $index => $line) {
                $lineId = !empty($line['id'])
                    ? (int) $line['id']
                    : null;

                /*
             * Существующая строка:
             * меняем только описание и сумму.
             *
             * subscription_id, order_id и периоды
             * остаются прежними.
             */
                if ($lineId) {
                    $existingLine = $originalLines->get($lineId);

                    if (!$existingLine) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.id" =>
                            'Позиция не принадлежит этому инвойсу.',
                        ]);
                    }

                    $existingLine->update([
                        'description' => $line['description'],
                        'amount' => $line['amount'],
                    ]);

                    $submittedExistingIds[] = $lineId;

                    continue;
                }

                /*
             * Новая строка из формы редактирования
             * создаётся как ручная позиция.
             */
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                    'subscription_id' => null,
                    'order_id' => null,
                    'period_start' => null,
                    'period_end' => null,
                ]);
            }

            /*
         * Удаляем только старые строки,
         * которые пользователь убрал из формы.
         */
            $lineIdsToDelete = $originalLines
                ->keys()
                ->diff($submittedExistingIds);

            if ($lineIdsToDelete->isNotEmpty()) {
                $invoice->lines()
                    ->whereIn(
                        'id',
                        $lineIdsToDelete->all()
                    )
                    ->delete();
            }

            $totalAmount = collect($validated['lines'])
                ->sum(fn($line) => (float) $line['amount']);

            /*
         * Снимок компании и договора не изменяем.
         */
            $invoiceData = collect($validated)
                ->except([
                    'lines',
                    'company_id',
                    'status',
                    'payer_name',
                    'payer_voen',
                    'contract_reference',
                ])
                ->toArray();

            $invoiceData['status'] = 'draft';
            $invoiceData['total_amount'] = $totalAmount;

            $invoice->update($invoiceData);
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with(
                'success',
                'Черновик инвойса успешно обновлён.'
            );
    }

    public function issue(Invoice $invoice)
    {
        DB::transaction(function () use ($invoice) {
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
                    'issue' =>
                    'Нельзя выставить черновик с подтверждёнными платежами.',
                ]);
            }

            $contract = $invoice->contract;

            if (!$contract) {
                throw ValidationException::withMessages([
                    'issue' =>
                    'Инвойс не связан с договором.',
                ]);
            }

            $lines = $invoice->lines()
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'issue' =>
                    'В инвойсе должна быть хотя бы одна позиция.',
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

            $monthsByBillingPeriod = [
                'monthly' => 1,
                'quarterly' => 3,
                'semiannual' => 6,
                'annual' => 12,
            ];

            $nextBillingDates = [];
            $seenSubscriptions = [];

            foreach ($lines as $index => $line) {
                /*
             * Разовые и ручные позиции
             * не изменяют next_billing_date.
             */
                if (!$line->subscription_id) {
                    continue;
                }

                if (isset($seenSubscriptions[$line->subscription_id])) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        'Одна подписка не может быть добавлена в инвойс несколько раз.',
                    ]);
                }

                $seenSubscriptions[$line->subscription_id] = true;

                $subscription = $subscriptions->get(
                    $line->subscription_id
                );

                if (!$subscription) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        'Одна из подписок больше не существует.',
                    ]);
                }

                if (
                    (int) $subscription->contract_id
                    !== (int) $invoice->contract_id
                ) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        'Одна из подписок не принадлежит договору инвойса.',
                    ]);
                }

                if ($subscription->status !== 'active') {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "Подписка «{$line->description}» больше не активна.",
                    ]);
                }

                if (!$line->period_start || !$line->period_end) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "У позиции «{$line->description}» не указан расчётный период.",
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
                        'issue' =>
                        "У позиции «{$line->description}» неверный расчётный период.",
                    ]);
                }

                $subscriptionStart = Carbon::parse(
                    $subscription->start_date
                )->startOfDay();

                if ($periodStart->lt($subscriptionStart)) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "Период позиции «{$line->description}» начинается раньше подписки.",
                    ]);
                }

                $contractStart = Carbon::parse(
                    $contract->start_date
                )->startOfDay();

                if ($periodStart->lt($contractStart)) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "Период позиции «{$line->description}» начинается раньше договора.",
                    ]);
                }

                if ($contract->end_date) {
                    $contractEnd = Carbon::parse(
                        $contract->end_date
                    )->startOfDay();

                    if ($periodEnd->gt($contractEnd)) {
                        throw ValidationException::withMessages([
                            'issue' =>
                            "Период позиции «{$line->description}» выходит за срок договора.",
                        ]);
                    }
                }

                /*
             * Проверяем, что другой действующий инвойс
             * не содержит эту подписку за тот же период.
             *
             * Текущий черновик исключаем из проверки.
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
                    ->whereHas('invoice', function ($query) {
                        $query->where(
                            'status',
                            '!=',
                            'cancelled'
                        );
                    })
                    ->exists();

                if ($periodAlreadyInvoiced) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "По подписке «{$line->description}» уже есть инвойс за этот период.",
                    ]);
                }

                /*
             * Для custom-периода даты остаются ручными,
             * а next_billing_date пока не изменяем.
             */
                if ($subscription->billing_period === 'custom') {
                    continue;
                }

                $months = $monthsByBillingPeriod[$subscription->billing_period] ?? null;

                if (!$months) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "У подписки «{$line->description}» неизвестная периодичность.",
                    ]);
                }

                if (!$subscription->next_billing_date) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "У подписки «{$line->description}» не указана следующая дата выставления.",
                    ]);
                }

                $expectedPeriodStart = Carbon::parse(
                    $subscription->next_billing_date
                )->startOfDay();

                $expectedPeriodEnd = $expectedPeriodStart
                    ->copy()
                    ->addMonthsNoOverflow($months)
                    ->subDay();

                if (!$periodStart->equalTo($expectedPeriodStart)) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "Начало периода позиции «{$line->description}» больше не соответствует графику подписки.",
                    ]);
                }

                if (!$periodEnd->equalTo($expectedPeriodEnd)) {
                    throw ValidationException::withMessages([
                        'issue' =>
                        "Окончание периода позиции «{$line->description}» не соответствует графику подписки.",
                    ]);
                }

                $nextBillingDates[$subscription->id] = $periodEnd
                    ->copy()
                    ->addDay()
                    ->toDateString();
            }

            /*
         * Только после всех проверок
         * меняем статус инвойса.
         */
            $invoice->update([
                'status' => 'issued',
            ]);

            /*
         * Переводим подписки на следующие периоды.
         */
            foreach ($nextBillingDates as $subscriptionId => $date) {
                $subscriptions
                    ->get($subscriptionId)
                    ?->update([
                        'next_billing_date' => $date,
                    ]);
            }

            /*
         * Применяем кредитный баланс только после
         * успешного выставления черновика.
         */
            $company = $invoice->company()
                ->firstOrFail();

            $creditBalance = $company
                ->creditBalance()
                ->lockForUpdate()
                ->first();

            if ($creditBalance && $creditBalance->amount > 0) {
                $applied = $creditBalance->apply(
                    $invoice->total_amount,
                    $invoice
                );

                if ($applied > 0) {
                    $invoice->payments()->create([
                        'company_id' => $company->id,
                        'payment_date' => now()->toDateString(),
                        'amount' => $applied,
                        'payment_method' => 'transfer',
                        'status' => 'confirmed',
                        'comment' =>
                        "Автоматически применён Credit Balance ({$applied} ₼)",
                    ]);
                }
            }
        });

        $invoice->refresh();

        $message = $invoice->status === 'paid'
            ? 'Инвойс выставлен и полностью оплачен кредитным балансом.'
            : 'Инвойс успешно выставлен.';

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', $message);
    }

    public function cancel(Invoice $invoice)
    {
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
            if ($invoice->payments()->exists()) {
                throw ValidationException::withMessages([
                    'cancel' => 'Нельзя отменить инвойс, по которому зарегистрирован платёж.',
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
            foreach ($subscriptionLines as $line) {
                $subscription = $subscriptions->get(
                    $line->subscription_id
                );

                if (!$subscription) {
                    throw ValidationException::withMessages([
                        'cancel' => 'Одна из подписок больше не существует.',
                    ]);
                }

                if (!$line->period_start || !$line->period_end) {
                    throw ValidationException::withMessages([
                        'cancel' => "У позиции «{$line->description}» отсутствует расчётный период.",
                    ]);
                }

                /*
             * Если после отменяемого периода уже существует
             * другой инвойс или черновик, откатывать дату нельзя.
             *
             * Сначала необходимо удалить последующий черновик
             * либо отменить более поздний выставленный инвойс.
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
                    ->whereHas('invoice', function ($query) {
                        $query->where(
                            'status',
                            '!=',
                            'cancelled'
                        );
                    })
                    ->exists();

                if ($hasLaterPeriod) {
                    throw ValidationException::withMessages([
                        'cancel' =>
                        "Нельзя отменить позицию «{$line->description}»: "
                            . 'по подписке уже существует более поздний инвойс или черновик.',
                    ]);
                }
            }

            /*
         * Инвойс не удаляется — остаётся
         * в бухгалтерской истории.
         */
            $invoice->update([
                'status' => 'cancelled',
            ]);

            /*
         * Возвращаем стандартные подписки
         * на начало отменённого периода.
         */
            foreach ($subscriptionLines as $line) {
                $subscription = $subscriptions->get(
                    $line->subscription_id
                );

                if (!$subscription) {
                    continue;
                }

                /*
             * Пользовательский график пока
             * не пересчитываем автоматически.
             */
                if ($subscription->billing_period === 'custom') {
                    continue;
                }

                $subscription->update([
                    'next_billing_date' => Carbon::parse(
                        $line->period_start
                    )->toDateString(),
                ]);
            }
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with(
                'success',
                'Инвойс отменён. График подписок восстановлен.'
            );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        /*
     * Физически удалять разрешаем только черновики.
     * Выставленные счета должны сохраняться
     * в истории бухгалтерских документов.
     */
        if ($invoice->status !== 'draft') {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Удалить можно только черновик инвойса.'
                );
        }

        /*
     * Дополнительная защита для старых данных:
     * черновик с любым зарегистрированным платежом
     * удалять нельзя.
     */
        if ($invoice->payments()->exists()) {
            return redirect()
                ->route('invoices.show', $invoice)
                ->with(
                    'error',
                    'Нельзя удалить черновик, по которому зарегистрирован платёж.'
                );
        }

        $invoice->delete();

        return redirect()
            ->route('invoices.index')
            ->with(
                'success',
                'Черновик инвойса успешно удалён.'
            );
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
                    'billing_period_custom' =>
                    $subscription->billing_period_custom ?? null,

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
}
