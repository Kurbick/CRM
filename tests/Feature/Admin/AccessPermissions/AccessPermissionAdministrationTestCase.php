<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AccessPermissionAdministrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    /** @param list<string> $permissions */
    protected function actorWithPermissions(array $permissions): User
    {
        $role = $this->customRole('Access actor '.str()->random(6));
        $role->syncPermissions($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        return $user;
    }

    protected function customRole(string $displayName = 'Менеджер'): Role
    {
        return Role::query()->create([
            'name' => 'custom-'.str()->ulid(),
            'guard_name' => 'web',
            'display_name' => $displayName,
            'description' => 'Пользовательская группа.',
            'is_system' => false,
            'sort_order' => 100,
        ]);
    }
}
