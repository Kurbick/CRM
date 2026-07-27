<?php

namespace Tests\Feature\Admin\Users;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class UserAdministrationTestCase extends TestCase
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
        $role = Role::query()->create([
            'name' => 'test-role-'.str()->random(10),
            'guard_name' => 'web',
            'display_name' => 'Тестовая группа',
        ]);
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
}
