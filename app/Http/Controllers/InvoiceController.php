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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly SubscriptionBillingSchedule $billingSchedule,
    ) {}

    public function index(Company $company): JsonResponse
    {
        $invoices = $company->invoices()
            ->with('lines')
            ->get()
            ->each(function ($invoice) {
                $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);
            });

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
        $invoice->load(['company', 'lines', 'payments']);
        $invoice->append(['paid_amount', 'remaining_amount', 'is_overdue']);

        return response()->json($invoice);
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
}
