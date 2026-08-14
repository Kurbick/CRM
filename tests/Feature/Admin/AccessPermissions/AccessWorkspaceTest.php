<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionRegistry;

class AccessWorkspaceTest extends AccessPermissionAdministrationTestCase
{
    public function test_workspace_lists_groups_and_renders_selected_group_permissions_without_accordions(): void
    {
        $actor = $this->actorWithPermissions([
            'access_permissions.view',
            'access_permissions.update',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
        ]);
        $selected = $this->customRole('Менеджеры');
        User::factory()->create()->assignRole($selected);

        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $selected->id]));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSeeText('Доступ')
            ->assertSeeText('Группы')
            ->assertSeeText($selected->display_name)
            ->assertSee(route('admin.access-permissions.index', ['role' => $selected->id]))
            ->assertSee('data-permission-workspace', false)
            ->assertDontSee('data-permission-matrix', false)
            ->assertDontSee('aria-controls="permission-category-invoices"', false)
            ->assertSeeText('Переименовать')
            ->assertSeeText('Группа назначена пользователям и не может быть удалена.');

        $this->assertSame(43, substr_count($content, 'name="permissions[]"'));
        $this->assertSame(11, substr_count($content, 'data-permission-category='));

        foreach (PermissionRegistry::grouped() as $category) {
            $response->assertSeeText($category['label']);
        }
    }

    public function test_workspace_create_redirects_to_the_newly_created_group(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view', 'roles.create']);

        $response = $this->actingAs($actor)->post(route('admin.roles.store'), [
            'display_name' => 'Новая группа',
            'description' => null,
        ]);
        $role = Role::query()->where('display_name', 'Новая группа')->firstOrFail();

        $response->assertRedirect(route('admin.access-permissions.index', ['role' => $role->id]));
        $this->get(route('admin.access-permissions.index', ['role' => $role->id]))
            ->assertSeeText('Новая группа');
    }

    public function test_system_group_has_no_mutation_menu_even_for_authorized_administrator(): void
    {
        $admin = $this->administrator();
        $administratorRole = Role::findByName('administrator');

        $this->actingAs($admin)
            ->get(route('admin.access-permissions.index', ['role' => $administratorRole->id]))
            ->assertDontSee('aria-label="Действия с группой"', false)
            ->assertDontSee(route('admin.roles.update', $administratorRole))
            ->assertDontSee(route('admin.roles.destroy', $administratorRole));
    }
}
