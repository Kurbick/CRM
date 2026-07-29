<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class AccessPermissionUpdateTest extends AccessPermissionAdministrationTestCase
{
    public function test_accountant_viewer_and_custom_managed_permissions_are_updated_exactly(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        foreach ([Role::findByName('accountant'), Role::findByName('viewer'), $this->customRole()] as $role) {
            $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => ['invoices.update']])->assertRedirect();
            $this->assertSame(['invoices.update'], $role->fresh()->permissions->pluck('name')->all());
        }
    }

    public function test_empty_selection_removes_all_managed_permissions_and_is_idempotent(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $role = $this->customRole();
        $role->syncPermissions(['dashboard.view']);
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), [])->assertRedirect();
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => []])->assertRedirect();
        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_validation_rejects_unknown_duplicate_and_other_guard_slugs(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $role = $this->customRole();
        Permission::query()->create(['name' => 'api.permission', 'guard_name' => 'sanctum']);
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => ['unknown.permission']])->assertSessionHasErrors('permissions.0');
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => ['dashboard.view', 'dashboard.view']])->assertSessionHasErrors('permissions.1');
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => ['api.permission']])->assertSessionHasErrors('permissions.0');
    }

    public function test_non_web_role_and_missing_permission_are_rejected(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $apiRole = Role::query()->create(['name' => 'api-role', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $apiRole), ['permissions' => []])->assertNotFound();
        $this->actingAs($this->actorWithPermissions(['access_permissions.view']))->put(route('admin.access-permissions.update', $this->customRole()), ['permissions' => []])->assertForbidden();
    }

    public function test_update_preserves_role_users_metadata_permission_records_and_direct_permissions(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $role = $this->customRole();
        $user = User::factory()->create();
        $user->assignRole($role);
        $before = [$role->name, $role->guard_name, $role->display_name, $role->description, $role->is_system, $role->sort_order];
        $permissionCount = Permission::query()->count();
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => ['payments.confirm']])->assertRedirect();
        $role->refresh();
        $this->assertSame($before, [$role->name, $role->guard_name, $role->display_name, $role->description, $role->is_system, $role->sort_order]);
        $this->assertTrue($user->fresh()->hasRole($role));
        $this->assertCount(0, $user->permissions);
        $this->assertSame($permissionCount, Permission::query()->count());
    }

    public function test_cache_is_reset_redirect_is_explicit_and_flash_is_one_time(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view', 'access_permissions.update']);
        $role = $this->customRole();
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->assertFalse($user->can('contracts.update'));
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => ['contracts.update']])
            ->assertRedirect(route('admin.access-permissions.index', ['role' => $role->id]));
        $this->assertTrue($user->fresh()->can('contracts.update'));
        $this->get(route('admin.access-permissions.index', ['role' => $role->id]))->assertSeeText('Права доступа группы обновлены.');
        $this->get(route('home'))->assertDontSeeText('Права доступа группы обновлены.');
    }
}
