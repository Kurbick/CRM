<?php

namespace App\Actions\Companies;

use App\Exceptions\CompanyDeletionException;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteCompany
{
    public function __construct(
        private readonly CompanyDeletionDependencies $dependencies
    ) {}

    public function canDelete(Company $company): bool
    {
        return ! $this->dependencies->hasBlockingDependencies($company);
    }

    public function handle(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $lockedCompany = Company::query()
                ->lockForUpdate()
                ->findOrFail($company->getKey());

            if ($this->dependencies->hasBlockingDependencies($lockedCompany)) {
                throw CompanyDeletionException::dependencies();
            }

            try {
                $lockedCompany->delete();
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                throw CompanyDeletionException::concurrentDependency($exception);
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
