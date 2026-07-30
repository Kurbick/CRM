<?php

namespace App\Actions\Subscriptions;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\InvoiceDueDateSynchronizer;
use App\Services\SubscriptionLifecycle;
use Illuminate\Support\Facades\DB;

final class UpdateSubscription
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly InvoiceDueDateSynchronizer $dueDateSynchronizer,
    ) {}

    public function handle(Subscription $subscription, array $attributes): Subscription
    {
        return DB::transaction(function () use ($subscription, $attributes): Subscription {
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

            return $lockedSubscription;
        });
    }
}
