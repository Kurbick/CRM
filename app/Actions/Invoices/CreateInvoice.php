<?php

namespace App\Actions\Invoices;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoiceDueDateCalculator;
use App\Services\SubscriptionBillingSchedule;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use App\Support\Invoices\InvoiceSellerSnapshot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateInvoice
{
    public function __construct(
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly InvoiceSellerSnapshot $sellerSnapshot,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function execute(
        Company $company,
        Contract $contract,
        array $attributes,
        array $lines,
        bool $canonicalizeSubjectAmounts = false,
        ?User $actor = null,
    ): Invoice {
        try {
            return DB::transaction(function () use ($company, $contract, $attributes, $lines, $canonicalizeSubjectAmounts, $actor): Invoice {
                $lockedCompany = Company::query()
                    ->select(['id', 'name', 'short_name', 'voen'])
                    ->whereKey($company->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedContract = Contract::query()
                    ->select(['id', 'company_id', 'contract_number', 'start_date', 'end_date'])
                    ->whereKey($contract->getKey())
                    ->where('company_id', $lockedCompany->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedContract) {
                    throw ValidationException::withMessages([
                        'contract_id' => 'Выбранный договор не принадлежит компании.',
                    ]);
                }

                $normalizedLines = $this->normalizeLines(
                    $lines,
                    $lockedContract,
                    $canonicalizeSubjectAmounts,
                );

                $orderIds = collect($normalizedLines)
                    ->pluck('order_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $subscriptionIds = collect($normalizedLines)
                    ->pluck('subscription_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $dueDate = $this->dueDateCalculator->calculate(
                    issueDate: (string) $attributes['issue_date'],
                    manualDueDate: $attributes['due_date'] ?? null,
                    contractId: (int) $lockedContract->id,
                    orderIds: $orderIds,
                    subscriptionIds: $subscriptionIds,
                );

                $totalMinorUnits = array_sum(array_map(
                    fn (array $line): int => $this->toMinorUnits($line['amount']),
                    $normalizedLines
                ));

                $invoice = $lockedCompany->invoices()->create([
                    'contract_id' => $lockedContract->id,
                    'invoice_number' => $attributes['invoice_number'],
                    'issue_date' => $attributes['issue_date'],
                    'due_date' => $dueDate,
                    'period_start' => null,
                    'period_end' => null,
                    'total_amount' => $this->formatMinorUnits($totalMinorUnits),
                    'status' => 'draft',
                    ...$this->sellerSnapshot->toArray(),
                    'payer_name' => $lockedCompany->name,
                    'payer_voen' => $lockedCompany->voen,
                    'contract_reference' => $lockedContract->contract_number,
                    'comment' => $attributes['comment'] ?? null,
                ]);

                foreach ($normalizedLines as $line) {
                    $invoice->lines()->create($line);
                }

                $this->activityRecorder->record(
                    $lockedCompany,
                    CompanyActivityEventType::InvoiceCreated,
                    CompanyActivityCategory::Invoices,
                    CompanyActivityVisibilityScope::Financials,
                    subject: $invoice,
                    metadata: CompanyActivitySnapshot::invoice($invoice, $lockedContract),
                    actor: $actor,
                );

                return $invoice;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'billing_occurrence_key')) {
                throw $exception;
            }

            throw ValidationException::withMessages(
                $canonicalizeSubjectAmounts
                    ? ['lines' => 'Этот расчётный период уже зарезервирован другим инвойсом.']
                    : ['lines' => 'Эта billing occurrence уже зарезервирована.'],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(
        array $lines,
        Contract $contract,
        bool $canonicalizeSubjectAmounts,
    ): array {
        $orders = [];
        $subscriptions = [];
        $seenOrders = [];
        $seenSubscriptions = [];
        $seenOccurrences = [];

        foreach ($lines as $index => $line) {
            $orderId = isset($line['order_id']) ? (int) $line['order_id'] : null;
            $subscriptionId = isset($line['subscription_id']) ? (int) $line['subscription_id'] : null;

            if ($orderId !== null && $subscriptionId !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Позиция не может одновременно быть заказом и подпиской.',
                ]);
            }

            if ($orderId !== null) {
                if (isset($seenOrders[$orderId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.order_id" => 'Эта разовая услуга уже добавлена в инвойс.',
                    ]);
                }

                $seenOrders[$orderId] = true;
                $orders[] = $orderId;
            }

            if ($subscriptionId !== null) {
                if (isset($seenSubscriptions[$subscriptionId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.subscription_id" => 'Эта подписка уже добавлена в инвойс.',
                    ]);
                }

                $seenSubscriptions[$subscriptionId] = true;
                $subscriptions[] = $subscriptionId;
            }
        }

        sort($orders);
        sort($subscriptions);

        $orderRows = $orders === []
            ? collect()
            : Order::query()
                ->whereIn('id', $orders)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'contract_id', 'status', 'payment_terms', 'price', 'title'])
                ->keyBy('id');
        $subscriptionRows = $subscriptions === []
            ? collect()
            : Subscription::query()
                ->whereIn('id', $subscriptions)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        $normalized = [];
        foreach ($lines as $index => $line) {
            $orderId = isset($line['order_id']) ? (int) $line['order_id'] : null;
            $subscriptionId = isset($line['subscription_id']) ? (int) $line['subscription_id'] : null;

            $normalizedLine = [
                'description' => $line['description'],
                'subscription_id' => $subscriptionId,
                'order_id' => $orderId,
                'period_start' => null,
                'period_end' => null,
                'billing_occurrence_key' => null,
            ];

            if ($orderId !== null) {
                $order = $orderRows->get($orderId);
                if (! $order
                    || (int) $order->contract_id !== (int) $contract->id
                    || $order->status === 'cancelled') {
                    throw ValidationException::withMessages([
                        "lines.{$index}.order_id" => 'Разовая услуга не принадлежит договору или отменена.',
                    ]);
                }

                if ($canonicalizeSubjectAmounts) {
                    $line['amount'] = $order->price;
                }
            }

            if ($subscriptionId === null) {
                if (($line['period_start'] ?? null) || ($line['period_end'] ?? null)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_start" => 'Расчётный период разрешён только для подписок.',
                    ]);
                }

                $normalizedLine['amount'] = $this->formatMinorUnits($this->toMinorUnits($line['amount']));
                $normalized[] = $normalizedLine;

                continue;
            }

            $subscription = $subscriptionRows->get($subscriptionId);
            if (! $subscription
                || (int) $subscription->contract_id !== (int) $contract->id
                || $subscription->status !== 'active') {
                throw ValidationException::withMessages([
                    "lines.{$index}.subscription_id" => 'Подписка не активна или не принадлежит договору.',
                ]);
            }

            if ($canonicalizeSubjectAmounts) {
                $line['amount'] = $subscription->amount;
            }

            $periodCount = $this->subscriptionPeriodCount($line, $index);

            if (! $subscription->next_billing_date) {
                throw ValidationException::withMessages([
                    "lines.{$index}.subscription_id" => 'У подписки не указана следующая дата выставления.',
                ]);
            }

            $periodStart = CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();

            if (array_key_exists('expected_period_start', $line)
                && $line['expected_period_start'] !== null
                && $line['expected_period_start'] !== '') {
                try {
                    $expectedPeriodStart = CarbonImmutable::parse($line['expected_period_start'])->startOfDay();
                } catch (\Throwable) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_count" => 'Некорректная ожидаемая дата расчётного периода.',
                    ]);
                }

                if (! $expectedPeriodStart->equalTo($periodStart)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_count" => 'Расчётный период подписки изменился. Обновите данные и выберите периоды заново.',
                    ]);
                }
            }

            try {
                $occurrences = $this->billingSchedule->occurrenceChain(
                    $subscription,
                    $periodStart,
                    $periodCount,
                );
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "lines.{$index}.subscription_id" => 'У подписки не заполнен корректный интервал биллинга.',
                ]);
            }

            $contractStart = Carbon::parse($contract->start_date)->startOfDay();
            $subscriptionStart = Carbon::parse($subscription->start_date)->startOfDay();
            $contractEnd = $contract->end_date
                ? Carbon::parse($contract->end_date)->startOfDay()
                : null;

            foreach ($occurrences as $occurrence) {
                if ($occurrence['period_start']->lt($subscriptionStart)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_start" => 'Расчётный период не может начинаться раньше подписки.',
                    ]);
                }
                if ($occurrence['period_start']->lt($contractStart)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_start" => 'Расчётный период не может начинаться раньше договора.',
                    ]);
                }
                if ($contractEnd && $occurrence['period_end']->gt($contractEnd)) {
                    $available = array_values(array_filter($occurrences, fn (array $candidate): bool => ! $contractEnd || $candidate['period_end']->lte($contractEnd)));
                    throw ValidationException::withMessages([
                        "lines.{$index}.period_count" => "Невозможно выставить {$periodCount} расчётных периодов: срок договора позволяет только ".count($available).'.',
                    ]);
                }

                $occurrenceKey = $occurrence['billing_occurrence_key'];
                if (isset($seenOccurrences[$occurrenceKey])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.subscription_id" => 'Эта подписка уже добавлена за указанный период.',
                    ]);
                }
                $seenOccurrences[$occurrenceKey] = true;

                $periodAlreadyInvoiced = InvoiceLine::query()
                    ->where('billing_occurrence_key', $occurrenceKey)
                    ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->exists();
                if ($periodAlreadyInvoiced) {
                    throw ValidationException::withMessages(
                        $canonicalizeSubjectAmounts
                            ? ["lines.{$index}.period_count" => 'Этот расчётный период уже зарезервирован другим инвойсом.']
                            : [
                                'lines' => 'Эта billing occurrence уже зарезервирована.',
                                "lines.{$index}.subscription_id" => 'По этой подписке уже существует инвойс за указанный период.',
                            ],
                    );
                }

                $normalizedLine['period_start'] = $occurrence['period_start']->toDateString();
                $normalizedLine['period_end'] = $occurrence['period_end']->toDateString();
                $normalizedLine['billing_occurrence_key'] = $occurrenceKey;
                $normalizedLine['amount'] = $this->formatMinorUnits($this->toMinorUnits($line['amount']));
                $normalized[] = $normalizedLine;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $line */
    private function subscriptionPeriodCount(array $line, int $index): int
    {
        $value = $line['period_count'] ?? 1;
        if (filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1
            || (int) $value > SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE) {
            throw ValidationException::withMessages([
                "lines.{$index}.period_count" => 'Количество расчётных периодов должно быть целым числом от 1 до '.SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE.'.',
            ]);
        }

        return (int) $value;
    }

    private function toMinorUnits(mixed $value): int
    {
        $decimal = trim((string) ($value ?? '0'));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $decimal, $matches) !== 1) {
            throw new \LogicException("Invalid Invoice decimal value [{$decimal}].");
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatMinorUnits(int $minorUnits): string
    {
        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
