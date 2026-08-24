<?php

namespace App\Actions\Subscriptions;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoiceDueDateSynchronizer;
use App\Services\SubscriptionLifecycle;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class UpdateSubscription
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly InvoiceDueDateSynchronizer $dueDateSynchronizer,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Subscription $subscription, array $attributes, ?User $actor = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $attributes, $actor): Subscription {
            // Keep the same Invoice -> Subscription lock order as issue/cancel.
            Invoice::query()
                ->whereIn('status', ['draft', 'issued', 'partially_paid'])
                ->whereHas('lines', fn ($query) => $query->where('subscription_id', $subscription->getKey()))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->lifecycle->applyUpdate($lockedSubscription, $attributes);
            $this->dueDateSynchronizer->synchronizeForSubscription($lockedSubscription);
            $lockedSubscription->loadMissing([
                'contract:id,company_id,contract_number',
                'serviceType:id,name,type',
            ]);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($lockedSubscription->contract),
                CompanyActivityEventType::ContractSubjectUpdated,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $lockedSubscription,
                metadata: CompanyActivitySnapshot::subject($lockedSubscription, $lockedSubscription->contract),
                actor: $actor,
            );

            return $lockedSubscription;
        });
    }
}
