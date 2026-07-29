<?php

namespace App\Actions\Orders;

use App\Exceptions\OrderDeletionException;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteOrder
{
    public function canDelete(Order $order): bool
    {
        return ! $this->hasBlockingDependencies($order);
    }

    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            if ($this->hasBlockingDependencies($lockedOrder)) {
                throw OrderDeletionException::dependencies();
            }

            try {
                $lockedOrder->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw OrderDeletionException::concurrentDependency($exception);
            }
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
