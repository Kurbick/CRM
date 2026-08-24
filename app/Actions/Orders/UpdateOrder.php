<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Services\InvoiceDueDateSynchronizer;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class UpdateOrder
{
    public function __construct(
        private readonly InvoiceDueDateSynchronizer $dueDateSynchronizer,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Order $order, array $attributes, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Order {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $lockedOrder->update($attributes);
            $this->dueDateSynchronizer->synchronizeForOrder($lockedOrder);
            $lockedOrder->loadMissing([
                'contract:id,company_id,contract_number',
                'serviceType:id,name,type',
            ]);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($lockedOrder->contract),
                CompanyActivityEventType::ContractSubjectUpdated,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $lockedOrder,
                metadata: CompanyActivitySnapshot::subject($lockedOrder, $lockedOrder->contract),
                actor: $actor,
            );

            return $lockedOrder;
        });
    }
}
