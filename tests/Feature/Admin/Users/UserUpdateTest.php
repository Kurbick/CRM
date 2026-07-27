<?php

namespace Tests\Feature\Admin\Users;

use App\Models\Role;
use App\Models\User;

class UserUpdateTest extends UserAdministrationTestCase
{
    public function test_basic_update_changes_only_name_and_normalized_email(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);
        $user = User::factory()->create(['password' => 'Old-Password-123!', 'must_change_password' => true, 'last_login_at' => now()]);
        $user->assignRole('viewer');
        $before = [$user->password, $user->must_change_password, $user->last_login_at?->toISOString(), $user->created_by];
        $this->actingAs($actor)->put(route('admin.users.update', $user), ['name' => ' Новое имя ', 'email' => ' NEW@EXAMPLE.TEST '])->assertRedirect();
        $user->refresh();
        $this->assertSame('Новое имя', $user->name);
        $this->assertSame('new@example.test', $user->email);
        $this->assertSame($before, [$user->password, $user->must_change_password, $user->last_login_at?->toISOString(), $user->created_by]);
        $this->assertTrue($user->hasRole('viewer'));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);
        User::factory()->create(['email' => 'taken@example.test']);
        $target = User::factory()->create();
        $this->actingAs($actor)->put(route('admin.users.update', $target), ['name' => 'Target', 'email' => ' TAKEN@EXAMPLE.TEST '])->assertSessionHasErrorsIn('updateUser', 'email');
    }

    public function test_role_update_replaces_role_and_clears_direct_permissions(): void
    {
        $actor = $this->actorWithPermissions(['users.assign_role']);
        $target = User::factory()->create();
        $target->assignRole('viewer');
        $target->givePermissionTo('dashboard.view');
        $this->actingAs($actor)->put(route('admin.users.role.update', $target), ['role_id' => Role::findByName('accountant')->id])->assertRedirect();
        $this->assertSame(['accountant'], $target->fresh()->roles->pluck('name')->all());
        $this->assertCount(0, $target->fresh()->permissions);
    }

    public function test_self_role_change_and_non_web_role_are_rejected(): void
    {
        $actor = $this->actorWithPermissions(['users.assign_role']);
        $this->actingAs($actor)->put(route('admin.users.role.update', $actor), ['role_id' => Role::findByName('viewer')->id])->assertSessionHasErrorsIn('updateRole', 'role_id');
        $apiRole = Role::query()->create(['name' => 'api', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        $this->put(route('admin.users.role.update', User::factory()->create()), ['role_id' => $apiRole->id])->assertSessionHasErrorsIn('updateRole', 'role_id');
    }

    public function test_last_administrator_is_protected_and_one_of_two_can_change(): void
    {
        $admin = $this->administrator();
        $otherActor = $this->actorWithPermissions(['users.assign_role']);
        $this->actingAs($otherActor)->put(route('admin.users.role.update', $admin), ['role_id' => Role::findByName('viewer')->id])->assertSessionHasErrorsIn('updateRole', 'role_id');
        $second = $this->administrator();
        $this->put(route('admin.users.role.update', $admin), ['role_id' => Role::findByName('viewer')->id])->assertRedirect();
        $this->assertTrue($admin->fresh()->hasRole('viewer'));
        $this->assertTrue($second->fresh()->hasRole('administrator'));
    }
}
