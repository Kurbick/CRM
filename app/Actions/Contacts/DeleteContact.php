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

final class DeleteContact
{
    public function __construct(
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function execute(CompanyContact $contact, ?User $actor = null): CompanyContact
    {
        return DB::transaction(function () use ($contact, $actor): CompanyContact {
            $lockedContact = CompanyContact::query()
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedContact->delete();

            $this->activityRecorder->record(
                CompanyActivitySnapshot::companyForContact($lockedContact),
                CompanyActivityEventType::ContactDeleted,
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
