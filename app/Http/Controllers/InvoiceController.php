<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\InvoiceDueDateCalculator;
use App\Services\InvoiceEditabilityService;
use App\Services\SubscriptionBillingSchedule;
use App\Support\Invoices\InvoiceSellerSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly InvoiceSellerSnapshot $sellerSnapshot,
    ) {}

    public function index(Company $company): JsonResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $invoices = $company->invoices()
            ->select([
                'id',
                'company_id',
                'contract_id',
                'invoice_number',
                'issue_date',
                'due_date',
                'status',
                'total_amount',
                'created_at',
                'updated_at',
            ])
            ->withSum([
                'payments as confirmed_paid_amount' => fn ($query) => $query
                    ->where('status', 'confirmed'),
            ], 'amount')
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $invoice): array => $this->compactProjection(
                $invoice,
                $invoice->getAttribute('confirmed_paid_amount')
            ));

        return response()->json($invoices);
    }

    public function store(StoreInvoiceRequest $request, Company $company): JsonResponse
    {
        $validated = $request->validated();

        try {
            [$invoice, $invoiceCompany, $contract, $createdLines] = DB::transaction(function () use (
                $validated,
                $company
            ): array {
                $invoiceCompany = Company::query()
                    ->select(['id', 'name', 'short_name', 'voen'])
                    ->whereKey($company->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $contract = Contract::query()
                    ->select(['id', 'company_id', 'contract_number'])
                    ->whereKey($validated['contract_id'])
                    ->where('company_id', $invoiceCompany->id)
                    ->lockForUpdate()
                    ->first();
                if (! $contract) {
                    throw ValidationException::withMessages([
                        'contract_id' => 'Выбранный договор не принадлежит компании.',
                    ]);
                }

                $lines = array_values($validated['lines']);
                [$orderIds, $subscriptionIds] = $this->sourceIds($lines);
                $orders = $orderIds === []
                    ? collect()
                    : Order::query()
                        ->whereIn('id', $orderIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id', 'contract_id', 'status'])
                        ->keyBy('id');
                $subscriptions = $subscriptionIds === []
                    ? collect()
                    : Subscription::query()
                        ->whereIn('id', $subscriptionIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                $normalizedLines = [];
                $occurrenceKeys = [];
                foreach ($lines as $index => $line) {
                    $orderId = $line['order_id'] ?? null;
                    $subscriptionId = $line['subscription_id'] ?? null;

                    if ($orderId !== null) {
                        $order = $orders->get((int) $orderId);
                        if (! $order
                            || (int) $order->contract_id !== (int) $contract->id
                            || $order->status === 'cancelled') {
                            throw ValidationException::withMessages([
                                "lines.{$index}.order_id" => 'Разовая услуга не принадлежит договору или отменена.',
                            ]);
                        }
                    }

                    $normalizedLine = [
                        'description' => $line['description'],
                        'amount' => $this->decimalValue($line['amount']),
                        'subscription_id' => $subscriptionId,
                        'order_id' => $orderId,
                        'period_start' => null,
                        'period_end' => null,
                        'billing_occurrence_key' => null,
                    ];

                    if ($subscriptionId === null) {
                        $normalizedLines[] = $normalizedLine;

                        continue;
                    }

                    $subscription = $subscriptions->get((int) $subscriptionId);
                    if (! $subscription
                        || (int) $subscription->contract_id !== (int) $contract->id
                        || $subscription->status !== 'active') {
                        throw ValidationException::withMessages([
                            "lines.{$index}.subscription_id" => 'Подписка не активна или не принадлежит договору.',
                        ]);
                    }

                    try {
                        $periodStart = CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();
                        $periodEnd = $this->billingSchedule->periodEnd(
                            $periodStart,
                            CarbonImmutable::parse($subscription->start_date)->startOfDay(),
                            $this->billingSchedule->intervalFor($subscription),
                        );
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.subscription_id" => 'У подписки не заполнен корректный интервал биллинга.',
                        ]);
                    }

                    $occurrenceKey = $this->billingSchedule->occurrenceKey(
                        (int) $subscription->id,
                        $periodStart,
                        $periodEnd,
                    );
                    $occurrenceKeys[] = $occurrenceKey;
                    $normalizedLine['period_start'] = $periodStart->toDateString();
                    $normalizedLine['period_end'] = $periodEnd->toDateString();
                    $normalizedLine['billing_occurrence_key'] = $occurrenceKey;
                    $normalizedLines[] = $normalizedLine;
                }

                if ($occurrenceKeys !== [] && InvoiceLine::query()
                    ->whereIn('billing_occurrence_key', $occurrenceKeys)
                    ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'lines' => 'Эта billing occurrence уже зарезервирована.',
                    ]);
                }

                $dueDate = $this->dueDateCalculator->calculate(
                    issueDate: $validated['issue_date'],
                    manualDueDate: $validated['due_date'] ?? null,
                    contractId: (int) $contract->id,
                    orderIds: $orderIds,
                    subscriptionIds: $subscriptionIds,
                );
                $totalMinorUnits = array_sum(array_map(
                    fn (array $line): int => $this->toMinorUnits($line['amount']),
                    $normalizedLines
                ));
                $invoiceData = [
                    'contract_id' => $contract->id,
                    'invoice_number' => $validated['invoice_number'],
                    'issue_date' => $validated['issue_date'],
                    'due_date' => $dueDate,
                    'period_start' => null,
                    'period_end' => null,
                    'total_amount' => $this->formatMinorUnits($totalMinorUnits),
                    'status' => 'draft',
                    ...$this->sellerSnapshot->toArray(),
                    'payer_name' => $invoiceCompany->name,
                    'payer_voen' => $invoiceCompany->voen,
                    'contract_reference' => $contract->contract_number,
                    'comment' => $validated['comment'] ?? null,
                ];
                $invoice = $invoiceCompany->invoices()->create($invoiceData);
                $createdLines = collect();
                foreach ($normalizedLines as $line) {
                    $createdLines->push($invoice->lines()->create($line));
                }

                return [$invoice, $invoiceCompany, $contract, $createdLines];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'billing_occurrence_key')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'lines' => 'Эта billing occurrence уже зарезервирована.',
            ]);
        }

        return response()->json($this->detailProjection(
            $invoice,
            $invoiceCompany,
            $contract,
            $createdLines,
            '0.00'
        ), 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

        return response()->json($this->detailProjectionFor($invoice));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validated();

        $invoice = DB::transaction(function () use ($validated, $invoice): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $editability = $this->editabilityService->evaluate($lockedInvoice);
            if (! $editability['editable']) {
                throw ValidationException::withMessages([
                    'invoice' => $this->editabilityMessage($editability['reason']),
                ]);
            }

            $lockedLines = $lockedInvoice->lines()
                ->select([
                    'invoice_lines.id',
                    'invoice_lines.invoice_id',
                    'invoice_lines.order_id',
                    'invoice_lines.subscription_id',
                ])
                ->orderBy('invoice_lines.id')
                ->lockForUpdate()
                ->get();

            $changes = $this->metadataChanges($lockedInvoice, $validated, $lockedLines);
            if ($changes !== []) {
                $lockedInvoice->update($changes);
            }

            return $lockedInvoice;
        });

        return response()->json($this->detailProjectionFor($invoice));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, mixed>
     */
    private function metadataChanges(Invoice $invoice, array $validated, Collection $lines): array
    {
        if ($invoice->status === 'issued') {
            $forbiddenFields = array_values(array_diff(array_keys($validated), ['comment']));
            if ($forbiddenFields !== []) {
                throw ValidationException::withMessages(array_fill_keys(
                    $forbiddenFields,
                    'Для выставленного инвойса разрешено изменять только комментарий.'
                ));
            }

            return array_key_exists('comment', $validated)
                ? ['comment' => $validated['comment']]
                : [];
        }

        $changes = [];
        foreach (['invoice_number', 'issue_date', 'comment'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        $isSourced = $lines->contains(
            fn (InvoiceLine $line): bool => $line->order_id !== null || $line->subscription_id !== null
        );
        if ($isSourced) {
            if (array_key_exists('due_date', $validated)) {
                throw ValidationException::withMessages([
                    'due_date' => 'Срок оплаты инвойса со связанными позициями рассчитывается сервером.',
                ]);
            }

            if (array_key_exists('issue_date', $validated)) {
                $changes['due_date'] = $this->dueDateCalculator->calculate(
                    issueDate: $validated['issue_date'],
                    manualDueDate: null,
                    contractId: (int) $invoice->contract_id,
                    orderIds: $lines->pluck('order_id')->filter()->all(),
                    subscriptionIds: $lines->pluck('subscription_id')->filter()->all(),
                );
            }

            return $changes;
        }

        $effectiveIssueDate = $validated['issue_date']
            ?? $this->dateValue($invoice->issue_date)
            ?? throw new LogicException('Invoice issue date is required.');
        $effectiveDueDate = array_key_exists('due_date', $validated)
            ? $validated['due_date']
            : $this->dateValue($invoice->due_date);

        if ($effectiveDueDate !== null && $effectiveDueDate < $effectiveIssueDate) {
            throw ValidationException::withMessages([
                'due_date' => 'Срок оплаты не может быть раньше даты выставления.',
            ]);
        }

        if (array_key_exists('due_date', $validated)) {
            $changes['due_date'] = $validated['due_date'];
        }

        return $changes;
    }

    private function editabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'confirmed_payment' => 'Инвойс уже получил оплату и больше не может быть изменён.',
            'cancelled' => 'Отменённый инвойс нельзя редактировать.',
            default => 'Инвойс в текущем состоянии нельзя редактировать.',
        };
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        DB::transaction(function () use ($invoice): void {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'Удалить можно только черновик инвойса.',
                ]);
            }

            if ($lockedInvoice->payments()->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Нельзя удалить инвойс, по которому зарегистрирован платёж.',
                ]);
            }

            $lockedInvoice->delete();
        });

        return response()->json(['message' => 'Инвойс удалён'], 200);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{list<int>, list<int>}
     */
    private function sourceIds(array $lines): array
    {
        $orderIds = [];
        $subscriptionIds = [];

        foreach ($lines as $index => $line) {
            $orderId = isset($line['order_id']) ? (int) $line['order_id'] : null;
            $subscriptionId = isset($line['subscription_id']) ? (int) $line['subscription_id'] : null;

            if ($orderId !== null && $subscriptionId !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Позиция не может одновременно быть заказом и подпиской.',
                ]);
            }

            if ($orderId !== null) {
                if (in_array($orderId, $orderIds, true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.order_id" => 'Эта разовая услуга уже добавлена в инвойс.',
                    ]);
                }

                $orderIds[] = $orderId;
            }

            if ($subscriptionId !== null) {
                if (in_array($subscriptionId, $subscriptionIds, true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.subscription_id" => 'Эта подписка уже добавлена в инвойс.',
                    ]);
                }

                $subscriptionIds[] = $subscriptionId;
            }
        }

        sort($orderIds);
        sort($subscriptionIds);

        return [$orderIds, $subscriptionIds];
    }

    /** @return array<string, mixed> */
    private function compactProjection(Invoice $invoice, mixed $confirmedPaidAmount): array
    {
        return [
            'id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'contract_id' => $invoice->contract_id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $this->dateValue($invoice->issue_date),
            'due_date' => $this->dateValue($invoice->due_date),
            'status' => $invoice->status,
            'total_amount' => $this->decimalValue($invoice->total_amount),
            'paid_amount' => $this->decimalValue($confirmedPaidAmount),
            'remaining_amount' => $this->remainingAmount($invoice->total_amount, $confirmedPaidAmount),
            'is_overdue' => $invoice->is_overdue,
            'created_at' => $invoice->created_at?->toJSON(),
            'updated_at' => $invoice->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailProjectionFor(Invoice $invoice): array
    {
        $company = $invoice->company()
            ->select(['companies.id', 'companies.name', 'companies.short_name'])
            ->firstOrFail();
        $contract = $invoice->contract_id === null
            ? null
            : $invoice->contract()
                ->select(['contracts.id', 'contracts.company_id', 'contracts.contract_number'])
                ->first();
        $lines = $invoice->lines()
            ->select([
                'invoice_lines.id',
                'invoice_lines.invoice_id',
                'invoice_lines.description',
                'invoice_lines.amount',
                'invoice_lines.period_start',
                'invoice_lines.period_end',
            ])
            ->orderBy('invoice_lines.id')
            ->get();
        $confirmedPaidAmount = $invoice->payments()
            ->where('status', 'confirmed')
            ->sum('amount');

        return $this->detailProjection(
            $invoice,
            $company,
            $contract,
            $lines,
            $confirmedPaidAmount
        );
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, mixed>
     */
    private function detailProjection(
        Invoice $invoice,
        Company $company,
        ?Contract $contract,
        Collection $lines,
        mixed $confirmedPaidAmount
    ): array {
        return [
            'id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'contract_id' => $invoice->contract_id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $this->dateValue($invoice->issue_date),
            'due_date' => $this->dateValue($invoice->due_date),
            'period_start' => $this->dateValue($invoice->period_start),
            'period_end' => $this->dateValue($invoice->period_end),
            'status' => $invoice->status,
            'total_amount' => $this->decimalValue($invoice->total_amount),
            'paid_amount' => $this->decimalValue($confirmedPaidAmount),
            'remaining_amount' => $this->remainingAmount($invoice->total_amount, $confirmedPaidAmount),
            'is_overdue' => $invoice->is_overdue,
            'comment' => $invoice->comment,
            'seller_name' => $invoice->seller_name,
            'seller_voen' => $invoice->seller_voen,
            'seller_bank_name' => $invoice->seller_bank_name,
            'seller_iban' => $invoice->seller_iban,
            'seller_bank_code' => $invoice->seller_bank_code,
            'seller_bank_voen' => $invoice->seller_bank_voen,
            'seller_swift' => $invoice->seller_swift,
            'payer_name' => $invoice->payer_name,
            'payer_voen' => $invoice->payer_voen,
            'contract_reference' => $invoice->contract_reference,
            'created_at' => $invoice->created_at?->toJSON(),
            'updated_at' => $invoice->updated_at?->toJSON(),
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => $company->short_name,
            ],
            'contract' => $contract === null ? null : [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ],
            'lines' => $lines
                ->map(fn (InvoiceLine $line): array => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'amount' => $this->decimalValue($line->amount),
                    'period_start' => $this->dateValue($line->period_start),
                    'period_end' => $this->dateValue($line->period_end),
                ])
                ->values()
                ->all(),
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }

    private function remainingAmount(mixed $totalAmount, mixed $paidAmount): string
    {
        return $this->formatMinorUnits(max(
            $this->toMinorUnits($totalAmount) - $this->toMinorUnits($paidAmount),
            0
        ));
    }

    private function decimalValue(mixed $value): string
    {
        return $this->formatMinorUnits($this->toMinorUnits($value));
    }

    private function toMinorUnits(mixed $value): int
    {
        $decimal = trim((string) ($value ?? '0'));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $decimal, $matches) !== 1) {
            throw new LogicException("Invalid Invoice decimal value [{$decimal}].");
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
