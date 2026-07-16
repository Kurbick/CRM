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

            'status' => 'required|in:draft,issued,partially_paid,paid,cancelled',

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

            // Автоматически применяем кредитный баланс компании
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
                        'comment' => "Автоматически применён Credit Balance ({$applied} ₼)",
                    ]);
                }
            }

            return $invoice;
        });

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Инвойс успешно выставлен.');
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
