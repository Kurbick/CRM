<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\Order;
use App\Models\User;
use App\Support\Access\PermissionName;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->can(PermissionName::ContractsView->value);
    }

    public function create(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractSubjectsCreate->value);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can(PermissionName::ContractSubjectsUpdate->value);
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->can(PermissionName::ContractSubjectsDelete->value);
    }
}
