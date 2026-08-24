<?php

namespace App\Actions\Subscriptions;

use App\Models\Contract;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\SubscriptionLifecycle;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class CreateSubscription
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Contract $contract, array $attributes, ?User $actor = null): Subscription
    {
        return DB::transaction(function () use ($contract, $attributes, $actor): Subscription {
            $serviceType = null;
            if (array_key_exists('service_name', $attributes)) {
                $serviceType = ServiceType::firstOrCreate(
                    [
                        'name' => trim((string) $attributes['service_name']),
                        'type' => 'subscription',
                    ],
                    [
                        'base_price' => $attributes['amount'],
                    ]
                );
                unset($attributes['service_name']);
                $attributes['service_type_id'] = $serviceType->id;
            }

            $attributes = $this->lifecycle->normalizeInterval($attributes);
            $subscription = $contract->subscriptions()->make($attributes);
            $subscription->next_billing_date = $attributes['start_date'];
            $subscription->save();

            if ($serviceType === null) {
                $serviceType = $subscription->serviceType()->first();
            }
            $subscription->setRelation('serviceType', $serviceType);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($contract),
                CompanyActivityEventType::ContractSubjectCreated,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $subscription,
                metadata: CompanyActivitySnapshot::subject($subscription, $contract),
                actor: $actor,
            );

            return $subscription;
        });
    }
}
