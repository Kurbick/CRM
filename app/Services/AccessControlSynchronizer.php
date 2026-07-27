<?php

namespace App\Services;

use App\Models\Role;
use App\Support\Access\PermissionRegistry;
use App\Support\Access\SystemRole;
use App\Support\Access\SystemRoleRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class AccessControlSynchronizer
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function sync(): void
    {
        $this->registrar->forgetCachedPermissions();

        try {
            DB::transaction(function (): void {
                foreach (PermissionRegistry::names() as $name) {
                    Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                }

                foreach (SystemRoleRegistry::all() as $definition) {
                    $role = Role::query()->where('name', $definition['name']->value)->where('guard_name', 'web')->first();
                    $created = $role === null;

                    if ($created) {
                        $role = Role::query()->create([
                            'name' => $definition['name']->value,
                            'guard_name' => 'web',
                            'display_name' => $definition['display_name'],
                            'description' => $definition['description'],
                            'is_system' => true,
                            'sort_order' => $definition['sort_order'],
                        ]);
                    } else {
                        $role->forceFill([
                            'is_system' => true,
                            'sort_order' => $definition['sort_order'],
                        ])->save();
                    }

                    if ($created || $definition['name'] === SystemRole::Administrator) {
                        $role->syncPermissions(array_map(
                            fn ($permission) => $permission->value,
                            $definition['permissions'],
                        ));
                    }
                }
            });
        } finally {
            $this->registrar->forgetCachedPermissions();
        }
    }
}
