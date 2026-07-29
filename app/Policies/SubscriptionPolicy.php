<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Access\PermissionName;

final class SubscriptionPolicy
{
    public function create(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractSubjectsCreate->value);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->can(PermissionName::ContractSubjectsUpdate->value);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->can(PermissionName::ContractSubjectsDelete->value);
    }
}
