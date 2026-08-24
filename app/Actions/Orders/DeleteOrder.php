<?php

namespace App\Actions\Orders;

use App\Exceptions\OrderDeletionException;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteOrder
{
    public function __construct(private readonly CompanyActivityRecorder $activityRecorder) {}

    public function canDelete(Order $order): bool
    {
        return ! $this->hasBlockingDependencies($order);
    }

    public function handle(Order $order, ?User $actor = null): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            if ($this->hasBlockingDependencies($lockedOrder)) {
                throw OrderDeletionException::dependencies();
            }

            $lockedOrder->loadMissing([
                'contract:id,company_id,contract_number',
                'serviceType:id,name,type',
            ]);
            $contract = $lockedOrder->contract;
            $metadata = CompanyActivitySnapshot::subject($lockedOrder, $contract);

            try {
                $lockedOrder->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw OrderDeletionException::concurrentDependency($exception);
            }

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($contract),
                CompanyActivityEventType::ContractSubjectDeleted,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $lockedOrder,
                metadata: $metadata,
                actor: $actor,
            );
        });
    }

    private function hasBlockingDependencies(Order $order): bool
    {
        return $order->invoiceLines()->exists();
    }

    private function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $driverMessage = (string) ($errorInfo[2] ?? '');

        if ($sqlState === '23503') {
            return true;
        }

        if ($sqlState !== '23000') {
            return false;
        }

        if (in_array($driverCode, [1451, 1452], true)) {
            return true;
        }

        return in_array($driverCode, [19, 787], true)
            && $driverMessage === 'FOREIGN KEY constraint failed';
    }
}
