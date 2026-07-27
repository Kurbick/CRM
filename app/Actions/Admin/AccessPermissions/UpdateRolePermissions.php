<?php

namespace App\Actions\Admin\AccessPermissions;

use App\Exceptions\AccessPermissionException;
use App\Models\Role;
use App\Support\Access\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final class UpdateRolePermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param list<string> $permissions */
    public function handle(Role $role, array $permissions): Role
    {
        $this->registrar->forgetCachedPermissions();

        try {
            return DB::transaction(function () use ($role, $permissions): Role {
                $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->getKey());

                if ($lockedRole->guard_name !== 'web') {
                    throw new InvalidArgumentException('Группа должна использовать guard web.');
                }

                if ($lockedRole->isAdministrator()) {
                    throw AccessPermissionException::administratorIsImmutable();
                }

                $managedNames = PermissionRegistry::names();
                if (array_diff($permissions, $managedNames) !== []) {
                    throw new InvalidArgumentException('Выбрано неизвестное право доступа.');
                }

                $unknownNames = $lockedRole->permissions()
                    ->whereNotIn('name', $managedNames)
                    ->pluck('name')
                    ->all();

                $lockedRole->syncPermissions(array_values(array_unique([...$permissions, ...$unknownNames])));

                return $lockedRole->fresh('permissions');
            });
        } finally {
            $this->registrar->forgetCachedPermissions();
        }
    }
}
