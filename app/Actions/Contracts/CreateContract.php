<?php

namespace App\Actions\Contracts;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class CreateContract
{
    public function __construct(private readonly CompanyActivityRecorder $activityRecorder) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, array $attributes, ?User $actor = null): Contract
    {
        return DB::transaction(function () use ($company, $attributes, $actor): Contract {
            $contract = $company->contracts()->create($attributes);

            $this->activityRecorder->record(
                $company,
                CompanyActivityEventType::ContractCreated,
                CompanyActivityCategory::Contracts,
                CompanyActivityVisibilityScope::Contracts,
                subject: $contract,
                metadata: [
                    'contract_number' => $contract->contract_number,
                    'start_date' => $contract->start_date?->toDateString(),
                    'end_date' => $contract->end_date?->toDateString(),
                    'status' => $contract->status,
                ],
                actor: $actor,
            );

            return $contract;
        });
    }
}
