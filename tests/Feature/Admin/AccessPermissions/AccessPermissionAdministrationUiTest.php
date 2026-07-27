<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Support\Access\PermissionRegistry;

class AccessPermissionAdministrationUiTest extends AccessPermissionAdministrationTestCase
{
    public function test_editable_matrix_uses_registry_categories_and_native_controls(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view', 'access_permissions.update']);
        $role = Role::findByName('accountant');
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $content = $response->getContent();

        $response->assertSeeText('Настройка разрешений для групп пользователей CRM.')
            ->assertSeeText($role->display_name)
            ->assertSee(route('admin.access-permissions.update', $role))
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('data-permission-matrix', false)
            ->assertSee('data-category-select-all', false)
            ->assertSee('x-bind:aria-expanded="open.toString()"', false)
            ->assertSee('aria-controls="permission-category-invoices"', false)
            ->assertSee('id="permission-category-invoices"', false)
            ->assertSee('x-cloak', false)
            ->assertSeeText('Сохранить права')
            ->assertDontSee('Выбрать всё для всех')
            ->assertDontSee('permission_slug')->assertDontSee('user_ids');
        $this->assertSame(43, substr_count($content, 'name="permissions[]"'));
        $this->assertSame(11, substr_count($content, 'data-category-select-all'));
        foreach (PermissionRegistry::grouped() as $category) {
            $response->assertSeeText($category['label']);
        }
    }

    public function test_read_only_user_sees_disabled_matrix_without_save_form(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $role = Role::findByName('viewer');
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $this->assertSame(43, substr_count($response->getContent(), 'name="permissions[]"'));
        $response->assertDontSee('Сохранить права')->assertDontSee('data-category-select-all', false)->assertSee('disabled', false);
    }

    public function test_selector_contains_only_web_roles_using_display_names(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $custom = $this->customRole('Проектные менеджеры');
        Role::query()->create(['name' => 'api-secret-name', 'guard_name' => 'sanctum', 'display_name' => 'API group']);
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $custom->id]));
        $response->assertSeeText('Проектные менеджеры')->assertDontSee('api-secret-name')->assertDontSeeText('API group')->assertDontSee($custom->name);
    }
}
