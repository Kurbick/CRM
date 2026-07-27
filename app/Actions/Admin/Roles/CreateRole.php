<?php

namespace App\Actions\Admin\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class CreateRole
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @param array{display_name: string, description: ?string} $data */
    public function handle(array $data): Role
    {
        $role = DB::transaction(function () use ($data): Role {
            $lastRole = Role::query()->where('guard_name', 'web')->orderByDesc('sort_order')->lockForUpdate()->first();

            return Role::query()->create([
                'name' => 'custom-'.Str::ulid(),
                'guard_name' => 'web',
                'display_name' => $data['display_name'],
                'description' => $data['description'],
                'is_system' => false,
                'sort_order' => ($lastRole?->sort_order ?? 0) + 10,
            ]);
        });

        $this->registrar->forgetCachedPermissions();

        return $role;
    }
}
