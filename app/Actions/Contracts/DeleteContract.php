<?php

namespace App\Actions\Contracts;

use App\Exceptions\ContractDeletionException;
use App\Models\Contract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteContract
{
    public function __construct(
        private readonly ContractDeletionDependencies $dependencies
    ) {}

    public function canDelete(Contract $contract): bool
    {
        return ! $this->dependencies->hasBlockingDependencies($contract);
    }

    public function handle(Contract $contract): void
    {
        DB::transaction(function () use ($contract): void {
            $lockedContract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if ($this->dependencies->hasBlockingDependencies($lockedContract)) {
                throw ContractDeletionException::dependencies();
            }

            try {
                $lockedContract->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw ContractDeletionException::concurrentDependency($exception);
            }
        });
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
            && str_contains($driverMessage, 'FOREIGN KEY constraint failed');
    }
}
