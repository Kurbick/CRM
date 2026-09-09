<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BillingPeriodPreview
{
    public function __construct(
        private readonly ActiveOrganizationContext $organizationContext,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly InvoiceVatCalculator $vatCalculator,
        private readonly InvoicePaymentAvailabilityService $money,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, count: int, subtotal: string, vat: string, total: string, planned_count: int, planned_subtotal: string, planned_vat: string, planned_total: string, planned_subtotal_display: string, planned_vat_display: string, planned_total_display: string}
     */
    public function forPeriod(CarbonImmutable $period): array
    {
        return $this->buildPreviewFromRows(
            $this->subscriptionRows($this->organizationContext->resolve()),
            $period,
        );
    }

    public function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.display_timezone', 'Asia/Baku'))->startOfDay();
    }

    /**
     * One joined read is used by Dashboard and also supplies the existing
     * active subscription counter, keeping the page free of per-row queries.
     *
     * @return array{active_subscription_count: int, preview: array<string, mixed>}
     */
    public function dashboardSummary(CarbonImmutable $period): array
    {
        $rows = $this->subscriptionRows($this->organizationContext->resolve());

        return [
            'active_subscription_count' => $rows->pluck('subscription_id')->unique()->count(),
            'preview' => $this->buildPreviewFromRows($rows, $period),
        ];
    }

    /** @return Collection<int, object> */
    private function subscriptionRows(?Organization $organization): Collection
    {
        if ($organization === null) {
            return collect();
        }

        return Subscription::query()
            ->join('contracts', 'contracts.id', '=', 'subscriptions.contract_id')
            ->join('companies', 'companies.id', '=', 'contracts.company_id')
            ->join('service_types', 'service_types.id', '=', 'subscriptions.service_type_id')
            ->leftJoin('invoice_lines', 'invoice_lines.subscription_id', '=', 'subscriptions.id')
            ->leftJoin('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('subscriptions.status', 'active')
            ->where('contracts.issuer_organization_id', $organization->getKey())
            ->select([
                'subscriptions.id as subscription_id',
                'subscriptions.contract_id',
                'subscriptions.title',
                'subscriptions.start_date',
                'subscriptions.next_billing_date',
                'subscriptions.billing_period',
                'subscriptions.custom_interval_value',
                'subscriptions.custom_interval_unit',
                'subscriptions.amount',
                'subscriptions.status as subscription_status',
                'contracts.company_id',
                'contracts.contract_number',
                'contracts.start_date as contract_start_date',
                'contracts.end_date as contract_end_date',
                'contracts.status as contract_status',
                'companies.name as company_name',
                'companies.status as company_status',
                'service_types.name as service_name',
                'invoice_lines.billing_occurrence_key as reserved_occurrence_key',
                'invoice_lines.period_start as reserved_period_start',
                'invoice_lines.period_end as reserved_period_end',
                'invoices.id as reserved_invoice_id',
                'invoices.invoice_number as reserved_invoice_number',
                'invoices.status as reserved_invoice_status',
                'invoices.subtotal_amount as reserved_invoice_subtotal',
                'invoices.vat_enabled as reserved_invoice_vat_enabled',
                'invoices.vat_rate as reserved_invoice_vat_rate',
                'invoices.vat_amount as reserved_invoice_vat',
                'invoices.total_amount as reserved_invoice_total',
                'subscriptions.service_type_id',
            ])
            ->orderBy('subscriptions.id')
            ->get();
    }

    /** @param object $row */
    private function subscriptionFromRow(object $row): Subscription
    {
        $subscription = new Subscription();
        $subscription->forceFill([
            'id' => $row->subscription_id,
            'contract_id' => $row->contract_id,
            'title' => $row->title,
            'start_date' => $row->start_date,
            'next_billing_date' => $row->next_billing_date,
            'billing_period' => $row->billing_period,
            'custom_interval_value' => $row->custom_interval_value,
            'custom_interval_unit' => $row->custom_interval_unit,
            'amount' => $row->amount,
            'status' => $row->subscription_status,
            'contract_start_date' => $row->contract_start_date,
            'contract_end_date' => $row->contract_end_date,
        ]);

        return $subscription;
    }

    /** @param object $row */
    private function isBillableSubscription(Subscription $subscription, object $row): bool
    {
        if ($subscription->status !== 'active' || $row->contract_status === 'terminated' || $row->company_status !== 'active') {
            return false;
        }

        return $subscription->start_date !== null && $row->contract_start_date !== null;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, array{invoice_id: int, invoice_number: ?string, status: string, subtotal: mixed, vat_enabled: bool, vat_rate: mixed, vat: mixed, total: mixed}>
     */
    private function reservedOccurrences(Subscription $subscription, Collection $rows): array
    {
        $reserved = [];
        foreach ($rows as $row) {
            if ($row->reserved_invoice_id === null || $row->reserved_invoice_status === 'cancelled') {
                continue;
            }

            $key = $row->reserved_occurrence_key;
            if (! is_string($key) || $key === '') {
                if ($row->reserved_period_start === null || $row->reserved_period_end === null) {
                    continue;
                }

                $key = $this->billingSchedule->occurrenceKey(
                    (int) $subscription->id,
                    CarbonImmutable::parse($row->reserved_period_start)->startOfDay(),
                    CarbonImmutable::parse($row->reserved_period_end)->startOfDay(),
                );
            }

            if (isset($reserved[$key])) {
                continue;
            }

            $reserved[$key] = [
                'invoice_id' => (int) $row->reserved_invoice_id,
                'invoice_number' => $row->reserved_invoice_number,
                'status' => (string) $row->reserved_invoice_status,
                'subtotal' => $row->reserved_invoice_subtotal,
                'vat_enabled' => (bool) $row->reserved_invoice_vat_enabled,
                'vat_rate' => $row->reserved_invoice_vat_rate,
                'vat' => $row->reserved_invoice_vat,
                'total' => $row->reserved_invoice_total,
            ];
        }

        return $reserved;
    }

    /**
     * @return list<array{period_start: CarbonImmutable, period_end: CarbonImmutable, billing_occurrence_key: string}>
     */
    private function occurrencesForMonth(Subscription $subscription, CarbonImmutable $period): array
    {
        try {
            $anchor = CarbonImmutable::parse($subscription->start_date)->startOfDay();
            $monthStart = $period->startOfMonth();
            $monthEnd = $period->endOfMonth()->startOfDay();
            $interval = $this->billingSchedule->intervalFor($subscription);
            $start = $this->firstOccurrenceOnOrAfter($anchor, $monthStart, $interval);
            $occurrences = [];

            while ($start->lte($monthEnd)) {
                $end = $this->billingSchedule->periodEnd($start, $anchor, $interval);
                if ($end->gte($start)) {
                    $occurrences[] = [
                        'period_start' => $start,
                        'period_end' => $end,
                        'billing_occurrence_key' => $this->billingSchedule->occurrenceKey((int) $subscription->id, $start, $end),
                    ];
                }
                $start = $this->billingSchedule->nextOccurrenceStart($start, $anchor, $interval);
            }

            return array_values(array_filter($occurrences, function (array $occurrence) use ($subscription, $monthStart, $monthEnd): bool {
                $contractStart = CarbonImmutable::parse($subscription->getAttribute('contract_start_date') ?? $monthStart)->startOfDay();
                $contractEndValue = $subscription->getAttribute('contract_end_date');
                $contractEnd = $contractEndValue ? CarbonImmutable::parse($contractEndValue)->startOfDay() : null;

                return $occurrence['period_start']->gte($monthStart)
                    && $occurrence['period_start']->lte($monthEnd)
                    && $occurrence['period_start']->gte($contractStart)
                    && ($contractEnd === null || $occurrence['period_end']->lte($contractEnd));
            }));
        } catch (\Throwable) {
            return [];
        }
    }

    private function firstOccurrenceOnOrAfter(
        CarbonImmutable $anchor,
        CarbonImmutable $monthStart,
        \App\ValueObjects\BillingInterval $interval,
    ): CarbonImmutable {
        if ($anchor->gte($monthStart)) {
            return $anchor;
        }

        if ($interval->unit === \App\Enums\CustomIntervalUnit::Day) {
            $days = $anchor->diffInDays($monthStart);
            $steps = intdiv($days, $interval->value);
            $candidate = $anchor->addDays($steps * $interval->value);

            return $candidate->lt($monthStart)
                ? $candidate->addDays($interval->value)
                : $candidate;
        }

        $candidate = $anchor;
        $guard = 0;
        while ($candidate->lt($monthStart) && $guard++ < 10000) {
            $candidate = $this->billingSchedule->nextOccurrenceStart($candidate, $anchor, $interval);
        }

        return $candidate;
    }

    /**
     * @return list<array{period_start: string, period_end: string, period_display: string, month: int, year: int}>
     */
    private function missedOccurrences(Subscription $subscription, string $periodStart): array
    {
        if (! $subscription->next_billing_date || ! $subscription->start_date) {
            return [];
        }

        try {
            $nextBillingDate = CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();
            $targetDate = CarbonImmutable::parse($periodStart)->startOfDay();
            if (! $nextBillingDate->lt($targetDate)) {
                return [];
            }

            $anchor = CarbonImmutable::parse($subscription->start_date)->startOfDay();
            $interval = $this->billingSchedule->intervalFor($subscription);
            $occurrences = [];
            $guard = 0;

            while ($nextBillingDate->lt($targetDate) && $guard++ < 10000) {
                $periodEnd = $this->billingSchedule->periodEnd($nextBillingDate, $anchor, $interval);
                $occurrences[] = [
                    'period_start' => $nextBillingDate->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'period_display' => $nextBillingDate->format('d.m.Y').' — '.$periodEnd->format('d.m.Y'),
                    'month' => (int) $nextBillingDate->month,
                    'year' => (int) $nextBillingDate->year,
                ];
                $nextBillingDate = $this->billingSchedule->nextOccurrenceStart(
                    $nextBillingDate,
                    $anchor,
                    $interval,
                );
            }

            return $occurrences;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param Collection<int, object> $rows */
    private function buildPreviewFromRows(Collection $rows, CarbonImmutable $period): array
    {
        $organization = $this->organizationContext->resolve();
        if ($organization === null) {
            return $this->emptyResult();
        }

        $groups = $rows->groupBy('subscription_id');
        $resultRows = [];
        $subtotalMinor = 0;
        $vatMinor = 0;
        $totalMinor = 0;
        $queueCount = 0;
        $queueSubtotalMinor = 0;
        $queueVatMinor = 0;
        $queueTotalMinor = 0;
        $seenKeys = [];
        $businessToday = $this->businessToday();

        foreach ($groups as $subscriptionRows) {
            $subscription = $this->subscriptionFromRow($subscriptionRows->first());
            $subscription->setRawAttributes([
                ...$subscription->getAttributes(),
                'contract_start_date' => $subscriptionRows->first()->contract_start_date,
                'contract_end_date' => $subscriptionRows->first()->contract_end_date,
            ], true);
            if (! $this->isBillableSubscription($subscription, $subscriptionRows->first())) {
                continue;
            }

            $reserved = $this->reservedOccurrences($subscription, $subscriptionRows);
            foreach ($this->occurrencesForMonth($subscription, $period) as $occurrence) {
                $key = $occurrence['billing_occurrence_key'];
                $existing = $reserved[$key] ?? null;
                if (($existing !== null && $existing['status'] !== 'draft') || isset($seenKeys[$key])) {
                    continue;
                }

                if ($existing !== null) {
                    $amountMinor = $this->money->toMinorUnits($existing['subtotal']);
                    $rowVatMinor = $this->money->toMinorUnits($existing['vat']);
                    $rowTotalMinor = $this->money->toMinorUnits($existing['total']);
                    $subtotal = $this->money->fromMinorUnits($amountMinor);
                    $vat = $this->money->fromMinorUnits($rowVatMinor);
                    $total = $this->money->fromMinorUnits($rowTotalMinor);
                } else {
                    $amountMinor = $this->money->toMinorUnits($subscription->amount);
                    $vatSnapshot = $this->vatCalculator->snapshotForOrganization($organization, $amountMinor);
                    $rowVatMinor = $this->money->toMinorUnits($vatSnapshot['vat_amount']);
                    $rowTotalMinor = $this->money->toMinorUnits($vatSnapshot['total_amount']);
                    $subtotal = $this->money->fromMinorUnits($amountMinor);
                    $vat = $vatSnapshot['vat_amount'];
                    $total = $vatSnapshot['total_amount'];
                }
                $seenKeys[$key] = true;
                $subtotalMinor += $amountMinor;
                $vatMinor += $rowVatMinor;
                $totalMinor += $rowTotalMinor;
                $source = $subscriptionRows->first();
                $periodStart = $occurrence['period_start']->toDateString();
                $periodEnd = $occurrence['period_end']->toDateString();
                // Occurrences are stored/calculated as date-only values. Compare
                // their calendar date in the business timezone, rather than
                // comparing UTC midnight with the local business-day boundary.
                $scheduledBusinessDate = CarbonImmutable::parse(
                    $periodStart,
                    $businessToday->getTimezone(),
                )->startOfDay();
                $isDue = $scheduledBusinessDate->lte($businessToday);
                $isCurrentOccurrence = $subscription->next_billing_date?->toDateString() === $periodStart;
                $identity = (int) $subscription->id.':'.$key;
                $queueStatus = $existing !== null
                    ? 'draft'
                    : (! $isDue
                        ? 'scheduled'
                        : ($isCurrentOccurrence ? 'pending' : 'missed'));
                $missedOccurrences = $queueStatus === 'missed'
                    ? $this->missedOccurrences($subscription, $periodStart)
                    : [];
                if ($queueStatus === 'missed' && $missedOccurrences === []) {
                    $missedOccurrences[] = [
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'period_display' => CarbonImmutable::parse($periodStart)->format('d.m.Y').' — '.CarbonImmutable::parse($periodEnd)->format('d.m.Y'),
                        'month' => (int) CarbonImmutable::parse($periodStart)->month,
                        'year' => (int) CarbonImmutable::parse($periodStart)->year,
                    ];
                }
                $missedCount = count($missedOccurrences);
                if (in_array($queueStatus, ['pending', 'draft'], true)) {
                    $queueCount++;
                    $queueSubtotalMinor += $amountMinor;
                    $queueVatMinor += $rowVatMinor;
                    $queueTotalMinor += $rowTotalMinor;
                }
                $resultRows[] = [
                    'identity' => $identity,
                    'subscription_id' => (int) $subscription->id,
                    'contract_id' => (int) $subscription->contract_id,
                    'company_id' => (int) $source->company_id,
                    'billing_occurrence_key' => $key,
                    'invoice_id' => $existing['invoice_id'] ?? null,
                    'invoice_number' => $existing['invoice_number'] ?? null,
                    'queue_status' => $queueStatus,
                    'eligible_for_draft_creation' => $existing === null && $isDue && $isCurrentOccurrence,
                    'scheduled_billing_date' => $periodStart,
                    'scheduled_billing_date_display' => $scheduledBusinessDate->format('d').'.'.$scheduledBusinessDate->format('m').'.'.$scheduledBusinessDate->format('Y'),
                    'missed_count' => $missedCount,
                    'missed_occurrences' => $missedOccurrences,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'next_billing_date' => $subscription->next_billing_date?->toDateString(),
                    'company' => $source->company_name,
                    'contract' => $source->contract_number,
                    'description' => $subscription->title ?: $source->service_name,
                    'period' => $occurrence['period_start']->format('d/m/Y').' — '.$occurrence['period_end']->format('d/m/Y'),
                    'subtotal' => $subtotal,
                    'subtotal_display' => $this->money->formatMinorUnits($amountMinor),
                    'vat' => $vat,
                    'vat_display' => $this->money->formatMinorUnits($rowVatMinor),
                    'total' => $total,
                    'total_display' => $this->money->formatMinorUnits($rowTotalMinor),
                ];
            }
        }

        return [
            'rows' => $resultRows,
            'count' => $queueCount,
            'subtotal' => $this->money->fromMinorUnits($queueSubtotalMinor),
            'subtotal_display' => $this->money->formatMinorUnits($queueSubtotalMinor),
            'vat' => $this->money->fromMinorUnits($queueVatMinor),
            'vat_display' => $this->money->formatMinorUnits($queueVatMinor),
            'total' => $this->money->fromMinorUnits($queueTotalMinor),
            'total_display' => $this->money->formatMinorUnits($queueTotalMinor),
            'planned_count' => count($resultRows),
            'planned_subtotal' => $this->money->fromMinorUnits($subtotalMinor),
            'planned_vat' => $this->money->fromMinorUnits($vatMinor),
            'planned_total' => $this->money->fromMinorUnits($totalMinor),
            'planned_subtotal_display' => $this->money->formatMinorUnits($subtotalMinor),
            'planned_vat_display' => $this->money->formatMinorUnits($vatMinor),
            'planned_total_display' => $this->money->formatMinorUnits($totalMinor),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'rows' => [],
            'count' => 0,
            'subtotal' => '0.00',
            'subtotal_display' => '0,00 ₼',
            'vat' => '0.00',
            'vat_display' => '0,00 ₼',
            'total' => '0.00',
            'total_display' => '0,00 ₼',
            'planned_count' => 0,
            'planned_subtotal' => '0.00',
            'planned_vat' => '0.00',
            'planned_total' => '0.00',
            'planned_subtotal_display' => '0,00 ₼',
            'planned_vat_display' => '0,00 ₼',
            'planned_total_display' => '0,00 ₼',
        ];
    }
}
