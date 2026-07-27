<?php

namespace App\Services;

use App\Exceptions\LastAdministratorException;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\SystemRole;

final class AdministratorProtectionService
{
    public function assertCanDeactivate(User $user): void
    {
        if ($this->isActiveAdministrator($user) && $this->lockedActiveAdministratorsCount() <= 1) {
            throw new LastAdministratorException;
        }
    }

    public function assertCanChangeRole(User $user, ?Role $newRole): void
    {
        if ($newRole?->name === SystemRole::Administrator->value) {
            return;
        }

        if ($this->isActiveAdministrator($user) && $this->lockedActiveAdministratorsCount() <= 1) {
            throw new LastAdministratorException;
        }
    }

    private function isActiveAdministrator(User $user): bool
    {
        return $user->isActive() && $user->hasRole(SystemRole::Administrator->value);
    }

    private function lockedActiveAdministratorsCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query
                ->where('name', SystemRole::Administrator->value)
                ->where('guard_name', 'web'))
            ->lockForUpdate()
            ->get(['users.id'])
            ->count();
    }
}
