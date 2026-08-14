<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Support\Access\PermissionRegistry;

class AccessPermissionAdministrationUiTest extends AccessPermissionAdministrationTestCase
{
    public function test_editable_workspace_uses_flat_registry_categories_and_native_controls(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view', 'access_permissions.update']);
        $role = Role::findByName('accountant');
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $content = $response->getContent();

        $response->assertSeeText('Управление группами пользователей и их правами.')
            ->assertSeeText($role->display_name)
            ->assertSee(route('admin.access-permissions.update', $role))
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('data-permission-workspace', false)
            ->assertSee('data-permission-scroll-area', false)
            ->assertSee('data-permission-actions', false)
            ->assertSee('data-category-select-all', false)
            ->assertDontSee('data-permission-matrix', false)
            ->assertDontSee('aria-controls="permission-category-invoices"', false)
            ->assertSeeText('Сохранить')
            ->assertDontSee('Выбрать всё для всех')
            ->assertDontSee('permission_slug')->assertDontSee('user_ids');
        $this->assertSame(43, substr_count($content, 'name="permissions[]"'));
        $this->assertSame(11, substr_count($content, 'data-category-select-all'));
        $this->assertLessThan(
            strpos($content, 'data-permission-actions'),
            strpos($content, 'data-permission-scroll-area')
        );
        foreach (PermissionRegistry::grouped() as $category) {
            $response->assertSeeText($category['label']);
        }
    }

    public function test_read_only_user_sees_disabled_workspace_without_save_form(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $role = Role::findByName('viewer');
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $this->assertSame(43, substr_count($response->getContent(), 'name="permissions[]"'));
        $response->assertSee('data-permission-scroll-area', false)
            ->assertDontSee('Сохранить права')->assertDontSee('data-permission-actions', false)
            ->assertDontSee('data-category-select-all', false)->assertSee('disabled', false);
    }

    public function test_group_list_contains_only_web_roles_using_display_names(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $custom = $this->customRole('Проектные менеджеры');
        Role::query()->create(['name' => 'api-secret-name', 'guard_name' => 'sanctum', 'display_name' => 'API group']);
        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $custom->id]));
        $response->assertSeeText('Проектные менеджеры')->assertDontSee('api-secret-name')->assertDontSeeText('API group')->assertDontSee($custom->name);
    }
}
