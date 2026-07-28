<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Access\PermissionName;

final class CompanyPolicy
{
    public function viewFinancials(User $user, Company $company): bool
    {
        return $user->can(PermissionName::CompaniesFinancialsView->value);
    }
}
