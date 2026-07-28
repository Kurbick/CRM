<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Support\Access\PermissionName;

final class CompanyContactPolicy
{
    public function create(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompanyContactsCreate->value);
    }

    public function update(User $user, CompanyContact $contact): bool
    {
        return $user->can(PermissionName::CompanyContactsUpdate->value);
    }

    public function delete(User $user, CompanyContact $contact): bool
    {
        return $user->can(PermissionName::CompanyContactsDelete->value);
    }
}
