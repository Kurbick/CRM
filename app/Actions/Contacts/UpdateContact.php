<?php

namespace App\Actions\Contacts;

use App\Models\CompanyContact;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\DB;

final class UpdateContact
{
    public function __construct(
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(CompanyContact $contact, array $attributes, ?User $actor = null): CompanyContact
    {
        return DB::transaction(function () use ($contact, $attributes, $actor): CompanyContact {
            $lockedContact = CompanyContact::query()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedContact->fill($attributes);
            if (! $lockedContact->isDirty()) {
                return $lockedContact;
            }

            $lockedContact->save();

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForContact($lockedContact),
                CompanyActivityEventType::ContactUpdated,
                CompanyActivityCategory::Contacts,
                CompanyActivityVisibilityScope::Contacts,
                subject: $lockedContact,
                metadata: CompanyActivitySnapshot::contact($lockedContact),
                actor: $actor,
            );

            return $lockedContact;
        });
    }
}
