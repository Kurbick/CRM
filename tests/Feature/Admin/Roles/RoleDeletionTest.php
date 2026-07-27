<?php

namespace Tests\Feature\Admin\Roles;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class RoleDeletionTest extends RoleAdministrationTestCase
{
    public function test_empty_custom_role_is_deleted_with_pivot_but_not_permission(): void
    {
        $actor = $this->actorWithPermissions(['roles.delete']);
        $role = $this->customRole();
        $permission = Permission::findByName('dashboard.view');
        $role->givePermissionTo($permission);

        $this->actingAs($actor)->delete(route('admin.roles.destroy', $role))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id]);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_success_message_is_consumed_after_the_first_redirected_page(): void
    {
        $actor = $this->actorWithPermissions(['roles.view', 'roles.delete']);
        $role = $this->customRole();

        $this->actingAs($actor)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));
        $this->get(route('admin.roles.index'))->assertSeeText('Группа удалена.');
        $this->get(route('dashboard'))->assertDontSeeText('Группа удалена.');
    }

    public function test_all_system_roles_are_protected(): void
    {
        $actor = $this->actorWithPermissions(['roles.delete']);
        foreach (['administrator', 'accountant', 'viewer'] as $name) {
            $role = Role::findByName($name);
            $this->actingAs($actor)->delete(route('admin.roles.destroy', $role))
                ->assertRedirect()->assertSessionHas('error', 'Нельзя удалить системную группу.');
            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
    }

    public function test_assigned_custom_role_and_user_are_unchanged(): void
    {
        $actor = $this->actorWithPermissions(['roles.delete']);
        $role = $this->customRole();
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($actor)->delete(route('admin.roles.destroy', $role))
            ->assertRedirect()->assertSessionHas('error', 'Нельзя удалить группу, пока она назначена пользователям.');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_delete_does_not_touch_other_roles_or_users_and_requires_permission(): void
    {
        $role = $this->customRole();
        $other = $this->customRole('Другая');
        $user = User::factory()->create();
        $this->actingAs($this->actorWithPermissions(['roles.view']))->delete(route('admin.roles.destroy', $role))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('roles', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertNotContains('GET', app('router')->getRoutes()->getByName('admin.roles.destroy')->methods());
    }

    public function test_non_web_role_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['roles.delete']);
        $role = Role::query()->create(['name' => 'api-role', 'guard_name' => 'sanctum', 'display_name' => 'API']);

        $this->actingAs($actor)->delete(route('admin.roles.destroy', $role))->assertNotFound();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
