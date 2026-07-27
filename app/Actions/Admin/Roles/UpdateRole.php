<?php

namespace App\Actions\Admin\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final class UpdateRole
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param array{display_name: string, description: ?string} $data */
    public function handle(Role $role, array $data): Role
    {
        $updatedRole = DB::transaction(function () use ($role, $data): Role {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            if ($lockedRole->guard_name !== 'web') {
                throw new InvalidArgumentException('Группа должна использовать guard web.');
            }

            $lockedRole->forceFill([
                'display_name' => $data['display_name'],
                'description' => $data['description'],
            ])->save();

            return $lockedRole;
        });

        $this->registrar->forgetCachedPermissions();

        return $updatedRole;
    }
}
