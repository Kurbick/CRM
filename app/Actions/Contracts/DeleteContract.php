<?php

namespace App\Actions\Contracts;

use App\Exceptions\ContractDeletionException;
use App\Models\Contract;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteContract
{
    private readonly CompanyActivityRecorder $activityRecorder;

    public function __construct(
        private readonly ContractDeletionDependencies $dependencies,
        ?CompanyActivityRecorder $activityRecorder = null,
    ) {
        $this->activityRecorder = $activityRecorder ?? app(CompanyActivityRecorder::class);
    }

    public function canDelete(Contract $contract): bool
    {
        return ! $this->dependencies->hasBlockingDependencies($contract);
    }

    public function handle(Contract $contract, ?User $actor = null): void
    {
        DB::transaction(function () use ($contract, $actor): void {
            $lockedContract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if ($this->dependencies->hasBlockingDependencies($lockedContract)) {
                throw ContractDeletionException::dependencies();
            }

            $metadata = CompanyActivitySnapshot::contract($lockedContract);

            try {
                $lockedContract->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw ContractDeletionException::concurrentDependency($exception);
            }

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyFor($lockedContract),
                CompanyActivityEventType::ContractDeleted,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $lockedContract,
                metadata: $metadata,
                actor: $actor,
            );
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
