<?php

namespace App\Actions\Subscriptions;

use App\Exceptions\SubscriptionDeletionException;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteSubscription
{
    public function canDelete(Subscription $subscription): bool
    {
        return ! $this->hasBlockingDependencies($subscription);
    }

    public function handle(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $lockedSubscription = Subscription::query()
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            if ($this->hasBlockingDependencies($lockedSubscription)) {
                throw SubscriptionDeletionException::dependencies();
            }

            try {
                $lockedSubscription->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw SubscriptionDeletionException::concurrentDependency($exception);
            }
        });
    }

    private function hasBlockingDependencies(Subscription $subscription): bool
    {
        return $subscription->invoiceLines()->exists();
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
