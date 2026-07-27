<?php

namespace App\Actions\Admin\Roles;

use App\Exceptions\RoleDeletionException;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final class DeleteRole
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            if ($lockedRole->guard_name !== 'web') {
                throw new InvalidArgumentException('Группа должна использовать guard web.');
            }

            if ($lockedRole->isSystem()) {
                throw RoleDeletionException::systemRole();
            }

            $assigned = DB::table(config('permission.table_names.model_has_roles'))
                ->where(config('permission.column_names.role_pivot_key') ?? 'role_id', $lockedRole->getKey())
                ->lockForUpdate()
                ->exists();

            if ($assigned) {
                throw RoleDeletionException::assignedRole();
            }

            $lockedRole->delete();
        });

        $this->registrar->forgetCachedPermissions();
    }
}
