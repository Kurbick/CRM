<?php

namespace App\Actions\Contacts;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class CreateContact
{
    public function __construct(
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Company $company, array $attributes, ?User $actor = null): CompanyContact
    {
        return DB::transaction(function () use ($company, $attributes, $actor): CompanyContact {
            $lockedCompany = Company::query()
                ->select(['id'])
                ->whereKey($company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $contact = $lockedCompany->contacts()->create($attributes);

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForContact($contact),
                CompanyActivityEventType::ContactCreated,
                CompanyActivityCategory::Contacts,
                CompanyActivityVisibilityScope::Contacts,
                subject: $contact,
                metadata: CompanyActivitySnapshot::contact($contact),
                actor: $actor,
            );

            return $contact;
        });
    }
}
