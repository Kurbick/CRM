<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoiceDueDateCalculator;
use App\Services\SubscriptionBillingSchedule;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueInvoice
{
    public function __construct(
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
        private readonly SubscriptionBillingSchedule $billingSchedule,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function execute(
        Invoice $invoice,
        User $actor,
        ?CarbonImmutable $billingPeriod = null,
    ): Invoice {
        $issuedInvoice = null;

        DB::transaction(function () use ($invoice, $actor, $billingPeriod, &$issuedInvoice): void {
            /* Блокируем инвойс, чтобы его нельзя было выставить одновременно. */
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_draft_only'),
                ]);
            }

            if ($lockedInvoice->payments()->where('status', 'confirmed')->exists()) {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_confirmed_payments'),
                ]);
            }

            $contract = $lockedInvoice->contract;
            $lines = $lockedInvoice->lines()->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_no_lines'),
                ]);
            }

            /* Блокируем все используемые подписки. */
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
            $billingOccurrenceCount = 0;
            $billingOccurrenceOutsidePeriod = false;

            foreach ($lines->whereNotNull('subscription_id')->groupBy('subscription_id') as $subscriptionId => $group) {
                $subscription = $subscriptions->get($subscriptionId);
                if (! $subscription || (int) $subscription->contract_id !== (int) $lockedInvoice->contract_id) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_contract_mismatch')]);
                }
                if ($subscription->status !== 'active' || ! $subscription->next_billing_date) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_unavailable', ['description' => $group->first()->description])]);
                }

                $ordered = $group->sortBy([['period_start', 'asc'], ['id', 'asc']])->values();
                $expectedStart = CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();
                try {
                    $expected = $this->billingSchedule->occurrenceChain($subscription, $expectedStart, $ordered->count());
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['issue' => __('invoices.errors.subscription_billing_invalid', ['description' => $group->first()->description])]);
                }

                foreach ($ordered as $index => $line) {
                    $occurrence = $expected[$index];
                    if (! $line->period_start || ! $line->period_end
                        || ! CarbonImmutable::parse($line->period_start)->startOfDay()->equalTo($occurrence['period_start'])
                        || ! CarbonImmutable::parse($line->period_end)->startOfDay()->equalTo($occurrence['period_end'])) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_schedule_invalid', ['description' => $line->description])]);
                    }
                    if ($occurrence['period_start']->lt(CarbonImmutable::parse($subscription->start_date)->startOfDay())
                        || $occurrence['period_start']->lt(CarbonImmutable::parse($contract->start_date)->startOfDay())
                        || ($contract->end_date && $occurrence['period_end']->gt(CarbonImmutable::parse($contract->end_date)->startOfDay()))) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_outside_term', ['description' => $line->description])]);
                    }
                    if ($line->billing_occurrence_key !== null && $line->billing_occurrence_key !== $occurrence['billing_occurrence_key']) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.line_key_invalid', ['description' => $line->description])]);
                    }
                    if (InvoiceLine::query()
                        ->where('billing_occurrence_key', $occurrence['billing_occurrence_key'])
                        ->where('invoice_id', '!=', $lockedInvoice->id)
                        ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
                        ->exists()) {
                        throw ValidationException::withMessages(['issue' => __('invoices.errors.period_exists', ['description' => $line->description])]);
                    }

                    if ($billingPeriod !== null) {
                        $billingOccurrenceCount++;
                        $billingOccurrenceOutsidePeriod = $billingOccurrenceOutsidePeriod
                            || $occurrence['period_start']->month !== $billingPeriod->month
                            || $occurrence['period_start']->year !== $billingPeriod->year;
                    }

                    $occurrenceKeys[$line->id] = $occurrence['billing_occurrence_key'];
                }

                $nextBillingDates[$subscription->id] = $this->billingSchedule
                    ->nextOccurrenceStart(
                        $expected[count($expected) - 1]['period_start'],
                        CarbonImmutable::parse($subscription->start_date)->startOfDay(),
                        $this->billingSchedule->intervalFor($subscription),
                    )
                    ->toDateString();
            }

            if ($billingPeriod !== null && ($billingOccurrenceCount === 0 || $billingOccurrenceOutsidePeriod)) {
                throw ValidationException::withMessages([
                    'issue' => __('invoices.errors.issue_billing_period'),
                ]);
            }

            $dueDate = $this->dueDateCalculator->calculate(
                issueDate: $lockedInvoice->issue_date,
                manualDueDate: $lockedInvoice->due_date,
                contractId: $lockedInvoice->contract_id,
                orderIds: $lines->pluck('order_id')->filter()->all(),
                subscriptionIds: $lines->pluck('subscription_id')->filter()->all(),
                contractEndDate: $contract?->end_date?->toDateString(),
            );

            $lockedInvoice->update([
                'status' => 'issued',
                'due_date' => $dueDate,
            ]);

            foreach ($occurrenceKeys as $lineId => $key) {
                $lines->firstWhere('id', $lineId)?->update([
                    'billing_occurrence_key' => $key,
                ]);
            }

            foreach ($nextBillingDates as $subscriptionId => $date) {
                $subscription = $subscriptions->get($subscriptionId);
                if ($subscription) {
                    $subscription->next_billing_date = $date;
                    $subscription->save();
                }
            }

            $this->activityRecorder->record(
                $contract
                    ? CompanyActivitySnapshot::companyFor($contract)
                    : CompanyActivitySnapshot::companyForInvoice($lockedInvoice),
                CompanyActivityEventType::InvoiceIssued,
                CompanyActivityCategory::Invoices,
                CompanyActivityVisibilityScope::Financials,
                subject: $lockedInvoice,
                metadata: CompanyActivitySnapshot::invoice($lockedInvoice, $contract),
                actor: $actor,
            );

            $issuedInvoice = $lockedInvoice;
        });

        return $issuedInvoice;
    }
}
