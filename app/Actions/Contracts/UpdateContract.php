<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use App\Models\User;
use App\Services\ActiveOrganizationContext;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateContract
{
    public function __construct(
        private readonly CompanyActivityRecorder $activityRecorder,
        private readonly ActiveOrganizationContext $organizationContext,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Contract $contract, array $attributes, ?User $actor = null): Contract
    {
        return DB::transaction(function () use ($contract, $attributes, $actor): Contract {
            $lockedContract = Contract::query()
                ->lockForUpdate()
                ->findOrFail($contract->getKey());
            $oldStatus = (string) $lockedContract->status;

            if (array_key_exists('issuer_organization_id', $attributes)) {
                $issuerId = (int) $attributes['issuer_organization_id'];
                $issuer = $issuerId > 0 ? $this->organizationContext->activeById($issuerId) : null;
                if ($issuer === null) {
                    throw ValidationException::withMessages([
                        'issuer_organization_id' => __('organizations.errors.active_required'),
                    ]);
                }

                if ($issuerId !== (int) $lockedContract->issuer_organization_id
                    && $lockedContract->invoices()->exists()) {
                    throw ValidationException::withMessages([
                        'issuer_organization_id' => __('organizations.errors.contract_issuer_locked'),
                    ]);
                }

                $attributes['issuer_organization_id'] = $issuer->getKey();
            }

            $lockedContract->update($attributes);

            if ($oldStatus !== (string) $lockedContract->status) {
                $this->activityRecorder->record(
                    CompanyActivitySnapshot::companyFor($lockedContract),
                    CompanyActivityEventType::ContractStatusChanged,
                    CompanyActivityCategory::Contracts,
                    CompanyActivityVisibilityScope::Contracts,
                    subject: $lockedContract,
                    metadata: [
                        'contract_number' => $lockedContract->contract_number,
                        'old_status' => $oldStatus,
                        'new_status' => $lockedContract->status,
                    ],
                    actor: $actor,
                );
            }

            return $lockedContract;
        });
    }
}
