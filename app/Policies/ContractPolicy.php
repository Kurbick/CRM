<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Support\Access\PermissionName;

final class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ContractsView->value);
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::ContractsCreate->value);
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractsUpdate->value);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractsDelete->value);
    }
}
