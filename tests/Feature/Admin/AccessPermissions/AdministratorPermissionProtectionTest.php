<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionRegistry;
use Illuminate\Support\Facades\Gate;

class AdministratorPermissionProtectionTest extends AccessPermissionAdministrationTestCase
{
    public function test_administrator_matrix_is_complete_checked_disabled_and_has_no_save_form(): void
    {
        $admin = $this->administrator();
        $role = Role::findByName('administrator');
        $response = $this->actingAs($admin)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $content = $response->getContent();
        $response->assertSeeText('Назначенных прав: 43 из 43')->assertSeeText('Группа „Администратор“ всегда имеет полный доступ.')
            ->assertDontSee('Сохранить права')->assertDontSee('data-category-select-all', false);
        $this->assertSame(43, substr_count($content, 'name="permissions[]"'));
        $this->assertSame(43, substr_count($content, 'checked'));
        $this->assertGreaterThanOrEqual(43, substr_count($content, 'disabled'));
    }

    public function test_server_rejects_administrator_update_and_preserves_full_registry(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $role = Role::findByName('administrator');
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => []])
            ->assertForbidden()->assertSeeText('Права группы „Администратор“ управляются системой и не могут быть изменены.');
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => ['arbitrary.permission']])
            ->assertSessionHasErrors('permissions.0');
        $this->assertEqualsCanonicalizing(PermissionRegistry::names(), $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_synchronizer_restores_full_registry_and_gate_does_not_bypass_business_ability(): void
    {
        $admin = $this->administrator();
        $role = Role::findByName('administrator');
        $role->syncPermissions([]);
        app(AccessControlSynchronizer::class)->sync();
        $this->assertEqualsCanonicalizing(PermissionRegistry::names(), $role->fresh()->permissions->pluck('name')->all());
        Gate::define('invoice-business-update', fn () => false);
        $this->assertFalse($admin->can('invoice-business-update'));
    }
}
