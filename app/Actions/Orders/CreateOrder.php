<?php

namespace App\Actions\Orders;

use App\Models\Contract;
use App\Models\Order;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class CreateOrder
{
    public function __construct(private readonly CompanyActivityRecorder $activityRecorder) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Contract $contract, array $attributes, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($contract, $attributes, $actor): Order {
            $serviceType = null;
            if (array_key_exists('service_name', $attributes)) {
                $serviceType = ServiceType::firstOrCreate(
                    [
                        'name' => trim((string) $attributes['service_name']),
                        'type' => 'one_time',
                    ],
                    [
                        'base_price' => $attributes['price'],
                    ]
                );
                unset($attributes['service_name']);
                $attributes['service_type_id'] = $serviceType->id;
            }

            $order = $contract->orders()->create($attributes);

            if ($serviceType === null) {
                $serviceType = $order->serviceType()->first();
            }
            $order->setRelation('serviceType', $serviceType);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($contract),
                CompanyActivityEventType::ContractSubjectCreated,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $order,
                metadata: CompanyActivitySnapshot::subject($order, $contract),
                actor: $actor,
            );

            return $order;
        });
    }
}
