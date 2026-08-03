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
use App\Services\InvoiceEditabilityService;
use App\Services\SubscriptionBillingSchedule;
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
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly SubscriptionBillingSchedule $billingSchedule,
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
        try {
            $invoice = DB::transaction(function () use ($request, $company) {
                $validated = $request->validated();
                $contract = Contract::query()
                    ->whereKey($validated['contract_id'])
                    ->where('company_id', $company->id)
                    ->first();
                if (! $contract) {
                    throw ValidationException::withMessages([
                        'contract_id' => 'Выбранный договор не принадлежит компании.',
                    ]);
                }

                $lines = $validated['lines'];
                $subscriptionIds = collect($lines)
                    ->pluck('subscription_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values();
                $subscriptions = Subscription::query()
                    ->whereIn('id', $subscriptionIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($lines as $index => &$line) {
                    if (! empty($line['subscription_id']) && ! empty($line['order_id'])) {
                        throw ValidationException::withMessages([
                            "lines.{$index}" => 'Позиция не может одновременно быть заказом и подпиской.',
                        ]);
                    }

                    if (! empty($line['order_id'])) {
                        $orderExists = Order::query()
                            ->whereKey($line['order_id'])
                            ->where('contract_id', $contract->id)
                            ->where('status', '!=', 'cancelled')
                            ->exists();
                        if (! $orderExists) {
                            throw ValidationException::withMessages([
                                "lines.{$index}.order_id" => 'Разовая услуга не принадлежит договору или отменена.',
                            ]);
                        }
                    }

                    if (empty($line['subscription_id'])) {
                        continue;
                    }

                    $subscription = $subscriptions->get((int) $line['subscription_id']);
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

                    if (InvoiceLine::query()
                        ->where('subscription_id', $subscription->id)
                        ->whereDate('period_start', $periodStart->toDateString())
                        ->whereDate('period_end', $periodEnd->toDateString())
                        ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                        ->exists()) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.subscription_id" => 'Эта billing occurrence уже зарезервирована.',
                        ]);
                    }

                    $line['period_start'] = $periodStart->toDateString();
                    $line['period_end'] = $periodEnd->toDateString();
                    $line['billing_occurrence_key'] = $this->billingSchedule->occurrenceKey(
                        (int) $subscription->id,
                        $periodStart,
                        $periodEnd,
                    );
                }
                unset($line);

                $invoiceData = collect($validated)->except(['lines', 'status'])->toArray();
                $invoiceData['status'] = 'draft';
                $invoiceData['total_amount'] = collect($lines)->sum('amount');
                $invoice = $company->invoices()->create($invoiceData);

                foreach ($lines as $line) {
                    $invoice->lines()->create($line);
                }

                return $invoice;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'billing_occurrence_key')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'lines' => 'Эта billing occurrence уже зарезервирована.',
            ]);
        }

        $invoice->load('lines');
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice, 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);

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

        return response()->json($this->detailProjection(
            $invoice,
            $company,
            $contract,
            $lines,
            $confirmedPaidAmount
        ));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = DB::transaction(function () use ($request, $invoice): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $editability = $this->editabilityService->evaluate($lockedInvoice);
            if (! $editability['editable']) {
                throw ValidationException::withMessages([
                    'invoice' => $this->editabilityMessage($editability['reason']),
                ]);
            }

            $lockedInvoice->update($request->validated());

            return $lockedInvoice;
        });

        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice);
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
