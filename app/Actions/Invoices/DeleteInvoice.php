<?php

namespace App\Actions\Invoices;

use App\Exceptions\Invoices\InvoiceDeletionException;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\SubscriptionBillingSchedule;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DeleteInvoice
{
    public function __construct(
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function execute(Invoice $invoice, ?User $actor = null): void
    {
        DB::transaction(function () use ($invoice, $actor): void {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lines = InvoiceLine::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'invoice_id',
                    'order_id',
                    'subscription_id',
                    'period_start',
                    'period_end',
                    'billing_occurrence_key',
                ]);

            $payments = Payment::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status']);

            $lineIds = $lines->pluck('id');
            $allocations = $lineIds->isEmpty()
                ? collect()
                : PaymentAllocation::query()
                    ->whereIn('invoice_line_id', $lineIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

            $creditEntries = CreditBalanceEntry::query()
                ->where('invoice_id', $lockedInvoice->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $subscriptionLines = $lines->whereNotNull('subscription_id');
            $subscriptionIds = $subscriptionLines
                ->pluck('subscription_id')
                ->unique()
                ->sort()
                ->values();
            $subscriptions = $subscriptionIds->isEmpty()
                ? collect()
                : Subscription::query()
                    ->whereIn('id', $subscriptionIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'next_billing_date'])
                    ->keyBy('id');

            $laterOccurrences = $subscriptionIds->isEmpty()
                ? collect()
                : InvoiceLine::query()
                    ->select(['invoice_lines.id', 'invoice_lines.subscription_id', 'invoice_lines.period_start'])
                    ->join('invoices as occurrence_invoices', 'occurrence_invoices.id', '=', 'invoice_lines.invoice_id')
                    ->whereIn('invoice_lines.subscription_id', $subscriptionIds)
                    ->where('invoice_lines.invoice_id', '!=', $lockedInvoice->getKey())
                    ->where('occurrence_invoices.status', '!=', 'cancelled')
                    ->orderBy('invoice_lines.id')
                    ->lockForUpdate()
                    ->get();

            if ($lockedInvoice->status !== 'draft') {
                throw InvoiceDeletionException::notDraft();
            }

            if ($payments->isNotEmpty()) {
                throw InvoiceDeletionException::paymentExists();
            }

            if ($allocations->isNotEmpty()) {
                throw InvoiceDeletionException::allocationExists();
            }

            if ($creditEntries->isNotEmpty()) {
                throw InvoiceDeletionException::creditEntryExists();
            }

            $rollbackDates = $this->subscriptionRollbackDates(
                $subscriptionLines,
                $subscriptions,
                $laterOccurrences,
            );

            if ($rollbackDates !== []) {
                $cases = collect($rollbackDates)
                    ->map(fn (string $date, int $subscriptionId): string => sprintf(
                        "WHEN %d THEN '%s'",
                        $subscriptionId,
                        $date,
                    ))
                    ->implode(' ');

                Subscription::query()
                    ->whereKey(array_keys($rollbackDates))
                    ->update([
                        'next_billing_date' => DB::raw("CASE id {$cases} ELSE next_billing_date END"),
                        'updated_at' => now(),
                    ]);
            }

            $lockedInvoice->delete();

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForInvoice($lockedInvoice),
                CompanyActivityEventType::InvoiceDeleted,
                CompanyActivityCategory::Invoices,
                CompanyActivityVisibilityScope::Financials,
                subject: $lockedInvoice,
                metadata: CompanyActivitySnapshot::invoice($lockedInvoice),
                actor: $actor,
            );
        });
    }

    /**
     * @param  Collection<int, InvoiceLine>  $subscriptionLines
     * @param  Collection<int, Subscription>  $subscriptions
     * @param  Collection<int, InvoiceLine>  $laterOccurrences
     * @return array<int, string>
     */
    private function subscriptionRollbackDates(
        Collection $subscriptionLines,
        Collection $subscriptions,
        Collection $laterOccurrences,
    ): array {
        $rollbackDates = [];

        foreach ($subscriptionLines->groupBy('subscription_id') as $subscriptionId => $lines) {
            $subscriptionId = (int) $subscriptionId;
            $subscription = $subscriptions->get($subscriptionId);

            if (! $subscription) {
                throw InvoiceDeletionException::inconsistentSubscriptionOccurrence();
            }

            $periodStarts = [];
            $periodEnds = [];

            foreach ($lines as $line) {
                if (! $line->period_start || ! $line->period_end || ! $line->billing_occurrence_key) {
                    throw InvoiceDeletionException::inconsistentSubscriptionOccurrence();
                }

                $periodStart = CarbonImmutable::parse($line->period_start)->startOfDay();
                $periodEnd = CarbonImmutable::parse($line->period_end)->startOfDay();
                $expectedKey = $this->billingSchedule->occurrenceKey(
                    $subscriptionId,
                    $periodStart,
                    $periodEnd,
                );

                if ($line->billing_occurrence_key !== $expectedKey || $periodEnd->lt($periodStart)) {
                    throw InvoiceDeletionException::inconsistentSubscriptionOccurrence();
                }

                $periodStarts[] = $periodStart;
                $periodEnds[] = $periodEnd;
            }

            $rollbackDate = collect($periodStarts)->min()->toDateString();
            $expectedCurrentDate = collect($periodEnds)->max()->addDay()->toDateString();
            $currentDate = $subscription->next_billing_date?->toDateString();

            if (! in_array($currentDate, [$rollbackDate, $expectedCurrentDate], true)) {
                throw InvoiceDeletionException::inconsistentSubscriptionOccurrence();
            }

            $hasLaterOccurrence = $laterOccurrences
                ->where('subscription_id', $subscriptionId)
                ->contains(fn (InvoiceLine $line): bool => $line->period_start !== null
                    && CarbonImmutable::parse($line->period_start)->startOfDay()->gt($rollbackDate));

            if ($hasLaterOccurrence) {
                throw InvoiceDeletionException::laterSubscriptionOccurrenceExists();
            }

            $rollbackDates[$subscriptionId] = $rollbackDate;
        }

        return $rollbackDates;
    }
}
