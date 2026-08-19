<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Services\InvoiceDueDateCalculator;
use App\Services\InvoiceEditabilityService;
use App\Services\InvoicePaymentAvailabilityService;
use App\Services\SubscriptionBillingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateInvoice
{
    public function __construct(
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
        private readonly InvoiceEditabilityService $editabilityService,
        private readonly InvoicePaymentAvailabilityService $paymentAvailabilityService,
        private readonly SubscriptionBillingSchedule $billingSchedule,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $lines  Null means metadata-only update.
     */
    public function execute(
        Invoice $invoice,
        array $attributes,
        ?array $lines = null,
        bool $preserveSubjectAmounts = false,
        array $subscriptionPeriodCounts = [],
    ): Invoice {
        return DB::transaction(function () use ($invoice, $attributes, $lines, $preserveSubjectAmounts, $subscriptionPeriodCounts): Invoice {
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
                    'invoice_lines.description',
                    'invoice_lines.amount',
                    'invoice_lines.period_start',
                    'invoice_lines.period_end',
                    'invoice_lines.billing_occurrence_key',
                ])
                ->orderBy('invoice_lines.id')
                ->lockForUpdate()
                ->get();

            if ($lines === null) {
                $changes = $this->metadataChanges($lockedInvoice, $attributes, $lockedLines);
                if ($changes !== []) {
                    $lockedInvoice->update($changes);
                }

                return $lockedInvoice->fresh();
            }

            if ($subscriptionPeriodCounts !== [] && $lockedInvoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'Изменять количество расчётных периодов можно только в черновике.',
                ]);
            }

            $lines = $this->resizeSubscriptionGroups(
                $lockedInvoice,
                $lockedLines,
                $lines,
                $subscriptionPeriodCounts,
            );

            if ($preserveSubjectAmounts) {
                $lines = $this->preserveExistingSubjectAmounts($lines, $lockedLines->keyBy('id'));
            }

            $paymentAvailability = $this->paymentAvailabilityService->evaluate($lockedInvoice);
            $newTotalMinor = $this->paymentAvailabilityService->sumToMinorUnits(
                collect($lines)->pluck('amount')
            );

            if ($newTotalMinor < $paymentAvailability['pending_minor']) {
                throw ValidationException::withMessages([
                    'lines' => 'Сумма инвойса не может быть меньше суммы ожидающих платежей: '
                        .$this->paymentAvailabilityService->formatMinorUnits($paymentAvailability['pending_minor']).'.',
                ]);
            }

            $originalLines = $lockedLines->keyBy('id');
            $submittedExistingIds = collect($lines)
                ->pluck('id')
                ->filter()
                ->map(fn ($lineId): int => (int) $lineId)
                ->values();
            $lineIdsToDelete = $originalLines->keys()->diff($submittedExistingIds);

            if (
                $lockedInvoice->status === 'issued'
                && $originalLines->only(collect($lineIdsToDelete)->all())->contains(
                    fn (InvoiceLine $line): bool => $line->subscription_id !== null || $line->order_id !== null
                )
            ) {
                throw ValidationException::withMessages([
                    'lines' => 'Нельзя удалить связанную позицию из уже выставленного инвойса.',
                ]);
            }

            $processedExistingIds = [];
            foreach ($lines as $index => $line) {
                $lineId = ! empty($line['id']) ? (int) $line['id'] : null;

                if ($lineId) {
                    $existingLine = $originalLines->get($lineId);
                    if (! $existingLine) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.id" => 'Позиция не принадлежит этому инвойсу.',
                        ]);
                    }

                    $submittedMetadata = [
                        'subscription_id' => $line['subscription_id'] ?? null,
                        'order_id' => $line['order_id'] ?? null,
                        'period_start' => $line['period_start'] ?? null,
                        'period_end' => $line['period_end'] ?? null,
                    ];
                    $storedMetadata = [
                        'subscription_id' => $existingLine->subscription_id,
                        'order_id' => $existingLine->order_id,
                        'period_start' => $existingLine->period_start?->toDateString(),
                        'period_end' => $existingLine->period_end?->toDateString(),
                    ];

                    if ($submittedMetadata != $storedMetadata) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.id" => 'Нельзя изменить служебную связь или расчётный период позиции.',
                        ]);
                    }

                    $linkedContractId = $existingLine->subscription_id
                        ? $existingLine->subscription()->value('contract_id')
                        : $existingLine->order()->value('contract_id');
                    if (
                        $linkedContractId !== null
                        && (int) $linkedContractId !== (int) $lockedInvoice->contract_id
                    ) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.id" => 'Связанная позиция не принадлежит договору инвойса.',
                        ]);
                    }

                    $existingLine->update([
                        'description' => $line['description'],
                        'amount' => $line['amount'],
                    ]);
                    $processedExistingIds[] = $lineId;

                    continue;
                }

                if (! empty($line['__subscription_resize_append'])) {
                    $lockedInvoice->lines()->create([
                        'description' => $line['description'],
                        'amount' => $line['amount'],
                        'subscription_id' => $line['subscription_id'],
                        'order_id' => null,
                        'period_start' => $line['period_start'],
                        'period_end' => $line['period_end'],
                        'billing_occurrence_key' => $line['billing_occurrence_key'],
                    ]);

                    continue;
                }

                if (
                    ! empty($line['subscription_id'])
                    || ! empty($line['order_id'])
                    || ! empty($line['period_start'])
                    || ! empty($line['period_end'])
                ) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.id" => 'Новая позиция может быть только ручной.',
                    ]);
                }

                $lockedInvoice->lines()->create([
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                    'subscription_id' => null,
                    'order_id' => null,
                    'period_start' => null,
                    'period_end' => null,
                ]);
            }

            if (collect($lineIdsToDelete)->isNotEmpty()) {
                $lockedInvoice->lines()->whereIn('id', collect($lineIdsToDelete)->all())->delete();
            }

            $remainingLinkedLines = $originalLines->only($processedExistingIds);
            $changes = $this->metadataChanges(
                $lockedInvoice,
                $attributes,
                $remainingLinkedLines,
                true,
            );
            $changes['total_amount'] = $this->paymentAvailabilityService->fromMinorUnits($newTotalMinor);

            $lockedInvoice->update($changes);

            return $lockedInvoice->fresh();
        });
    }

    /**
     * Resize only complete, existing subscription groups. New linked positions
     * are manufactured here from the locked draft snapshot, never accepted from
     * the browser as ordinary lines.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $periodCounts
     * @return list<array<string, mixed>>
     */
    private function resizeSubscriptionGroups(
        Invoice $invoice,
        Collection $lockedLines,
        array $lines,
        array $periodCounts,
    ): array {
        $targets = [];
        foreach ($periodCounts as $index => $entry) {
            $subscriptionId = filter_var($entry['subscription_id'] ?? null, FILTER_VALIDATE_INT);
            $count = filter_var($entry['period_count'] ?? null, FILTER_VALIDATE_INT);
            if ($subscriptionId === false || $count === false || $count < 1 || $count > SubscriptionBillingSchedule::MAX_OCCURRENCES_PER_INVOICE || isset($targets[$subscriptionId])) {
                throw ValidationException::withMessages([
                    "subscription_period_counts.{$index}" => 'Некорректное изменение количества расчётных периодов.',
                ]);
            }
            $targets[(int) $subscriptionId] = (int) $count;
        }

        $submittedById = collect($lines)
            ->filter(fn (array $line): bool => ! empty($line['id']))
            ->keyBy(fn (array $line): int => (int) $line['id']);
        $subscriptionGroups = $lockedLines->whereNotNull('subscription_id')->groupBy('subscription_id');

        foreach ($targets as $subscriptionId => $target) {
            if (! $subscriptionGroups->has($subscriptionId)) {
                throw ValidationException::withMessages([
                    'subscription_period_counts' => 'Нельзя изменить периоды подписки, которой нет в черновике.',
                ]);
            }
        }

        foreach ($subscriptionGroups as $subscriptionId => $group) {
            $existing = $group->sortBy([['period_start', 'asc'], ['id', 'asc']])->values();
            $submitted = $existing->filter(fn (InvoiceLine $line): bool => $submittedById->has($line->id));
            if ($submitted->isNotEmpty() && $submitted->count() !== $existing->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Нельзя удалить средний расчётный период подписки.',
                ]);
            }
            if ($submitted->isEmpty()) {
                if (isset($targets[(int) $subscriptionId])) {
                    throw ValidationException::withMessages([
                        'subscription_period_counts' => 'Нельзя изменить количество периодов у удалённой подписки.',
                    ]);
                }
                continue;
            }

            $target = $targets[(int) $subscriptionId] ?? $existing->count();
            if ($target < $existing->count()) {
                $trailingIds = $existing->slice($target)->pluck('id')->all();
                $lines = array_values(array_filter($lines, fn (array $line): bool => ! in_array((int) ($line['id'] ?? 0), $trailingIds, true)));
                continue;
            }
            if ($target === $existing->count()) {
                continue;
            }

            $subscription = Subscription::query()
                ->whereKey($subscriptionId)
                ->where('contract_id', $invoice->contract_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (! $subscription || ! $existing->first()->period_start) {
                throw ValidationException::withMessages(['subscription_period_counts' => 'Подписка больше недоступна для изменения периодов.']);
            }

            try {
                $chain = $this->billingSchedule->occurrenceChain(
                    $subscription,
                    CarbonImmutable::parse($existing->first()->period_start)->startOfDay(),
                    $target,
                );
            } catch (\Throwable) {
                throw ValidationException::withMessages(['subscription_period_counts' => 'У подписки не заполнен корректный интервал биллинга.']);
            }
            foreach ($existing as $index => $line) {
                $occurrence = $chain[$index];
                if (! $line->period_start || ! $line->period_end
                    || ! CarbonImmutable::parse($line->period_start)->startOfDay()->equalTo($occurrence['period_start'])
                    || ! CarbonImmutable::parse($line->period_end)->startOfDay()->equalTo($occurrence['period_end'])) {
                    throw ValidationException::withMessages(['subscription_period_counts' => 'Существующие периоды подписки не образуют корректную последовательность.']);
                }
            }

            $contractEnd = $invoice->contract?->end_date?->startOfDay();
            $contractStart = $invoice->contract?->start_date?->startOfDay();
            $snapshotLine = $submittedById->get($existing->first()->id);
            for ($index = $existing->count(); $index < $target; $index++) {
                $occurrence = $chain[$index];
                if ($contractEnd && $occurrence['period_end']->gt($contractEnd)) {
                    throw ValidationException::withMessages(['subscription_period_counts' => 'Расчётный период выходит за срок договора.']);
                }
                if ($occurrence['period_start']->lt(CarbonImmutable::parse($subscription->start_date)->startOfDay())
                    || ($contractStart && $occurrence['period_start']->lt($contractStart))) {
                    throw ValidationException::withMessages(['subscription_period_counts' => 'Расчётный период начинается раньше подписки или договора.']);
                }
                if (InvoiceLine::query()->where('billing_occurrence_key', $occurrence['billing_occurrence_key'])->where('invoice_id', '!=', $invoice->id)->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))->exists()) {
                    throw ValidationException::withMessages(['subscription_period_counts' => 'Один из добавляемых расчётных периодов уже зарезервирован.']);
                }
                $lines[] = [
                    '__subscription_resize_append' => true,
                    'description' => $snapshotLine['description'],
                    'amount' => $existing->first()->amount,
                    'subscription_id' => $subscription->id,
                    'order_id' => null,
                    'period_start' => $occurrence['period_start']->toDateString(),
                    'period_end' => $occurrence['period_end']->toDateString(),
                    'billing_occurrence_key' => $occurrence['billing_occurrence_key'],
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<string, mixed>
     */
    private function metadataChanges(
        Invoice $invoice,
        array $validated,
        Collection $lines,
        bool $lineEditing = false,
    ): array {
        if ($invoice->status === 'issued' && ! $lineEditing) {
            $forbiddenFields = array_values(array_diff(array_keys($validated), ['comment', 'lines']));
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

        $lineCollection = collect($lines);
        $isSourced = $lineCollection->contains(
            fn (InvoiceLine $line): bool => $line->order_id !== null || $line->subscription_id !== null
        );
        if ($isSourced) {
            if (array_key_exists('due_date', $validated) && ! $lineEditing) {
                throw ValidationException::withMessages([
                    'due_date' => 'Срок оплаты инвойса со связанными позициями рассчитывается сервером.',
                ]);
            }

            if (array_key_exists('issue_date', $validated)) {
                $changes['due_date'] = $this->dueDateCalculator->calculate(
                    issueDate: $validated['issue_date'],
                    manualDueDate: $lineEditing ? ($validated['due_date'] ?? null) : null,
                    contractId: (int) $invoice->contract_id,
                    orderIds: $lineCollection->pluck('order_id')->filter()->all(),
                    subscriptionIds: $lineCollection->pluck('subscription_id')->filter()->all(),
                );
            }

            return $changes;
        }

        $effectiveIssueDate = $validated['issue_date']
            ?? $this->dateValue($invoice->issue_date)
            ?? throw new \LogicException('Invoice issue date is required.');
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

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, InvoiceLine>  $existingLines
     * @return list<array<string, mixed>>
     */
    private function preserveExistingSubjectAmounts(array $lines, Collection $existingLines): array
    {
        foreach ($lines as $index => $line) {
            $lineId = ! empty($line['id']) ? (int) $line['id'] : null;
            $existingLine = $lineId ? $existingLines->get($lineId) : null;

            if ($existingLine && ($existingLine->order_id !== null || $existingLine->subscription_id !== null)) {
                $lines[$index]['amount'] = $existingLine->amount;
            }
        }

        return $lines;
    }

    private function editabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'confirmed_payment' => 'Инвойс уже получил оплату и больше не может быть изменён.',
            'cancelled' => 'Отменённый инвойс нельзя редактировать.',
            default => 'Инвойс в текущем состоянии нельзя редактировать.',
        };
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }
}
