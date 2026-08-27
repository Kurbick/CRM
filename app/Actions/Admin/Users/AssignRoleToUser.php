<?php

namespace App\Actions\Admin\Users;

use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorProtectionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AssignRoleToUser
{
    public function __construct(private readonly AdministratorProtectionService $protection) {}

    public function handle(User $user, Role $role): void
    {
        if ($role->guard_name !== 'web') {
            throw new InvalidArgumentException(__('admin.errors.invalid_guard'));
        }

        DB::transaction(function () use ($user, $role): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->protection->assertCanChangeRole($lockedUser, $role);
            $lockedUser->syncRoles([$role]);
            $lockedUser->syncPermissions([]);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        });
    }
}
