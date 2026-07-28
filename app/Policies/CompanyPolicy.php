<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Access\PermissionName;

final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::CompaniesView->value);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompaniesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::CompaniesCreate->value);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompaniesUpdate->value);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompaniesDelete->value);
    }

    public function viewFinancials(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompaniesFinancialsView->value);
    }
}
