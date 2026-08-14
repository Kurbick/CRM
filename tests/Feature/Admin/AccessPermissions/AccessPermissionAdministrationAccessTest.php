<?php

namespace Tests\Feature\Admin\AccessPermissions;

use App\Models\Role;
use App\Models\User;

class AccessPermissionAdministrationAccessTest extends AccessPermissionAdministrationTestCase
{
    public function test_guest_inactive_and_unprivileged_users_cannot_access(): void
    {
        $this->get(route('admin.access-permissions.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->inactive()->create())->get(route('admin.access-permissions.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.access-permissions.index'))->assertForbidden();
    }

    public function test_view_permission_allows_index_but_not_update(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $role = Role::findByName('viewer');
        $this->actingAs($actor)->get(route('admin.access-permissions.index'))->assertOk();
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => []])->assertForbidden();
    }

    public function test_update_permission_allows_direct_update_for_editable_role(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.update']);
        $role = $this->customRole();
        $this->actingAs($actor)->put(route('admin.access-permissions.update', $role), ['permissions' => ['dashboard.view']])->assertRedirect();
    }

    public function test_administrator_accesses_index_via_registry_gate_before(): void
    {
        $this->actingAs($this->administrator())->get(route('admin.access-permissions.index'))->assertOk();
        $this->get(route('home'))->assertSeeInOrder(['Пользователи', 'Доступ'])
            ->assertDontSee('<span>Группы</span>', false)
            ->assertDontSee('<span>Права доступа</span>', false);
    }

    public function test_invalid_and_non_web_role_selection_returns_not_found(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view']);
        $apiRole = Role::query()->create(['name' => 'api-role', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => 'invalid']))->assertNotFound();
        $this->get(route('admin.access-permissions.index', ['role' => $apiRole->id]))->assertNotFound();
    }

    public function test_access_sidebar_link_keeps_existing_view_boundaries(): void
    {
        $accessViewer = $this->actorWithPermissions(['access_permissions.view']);
        $this->actingAs($accessViewer)->get(route('home'))
            ->assertSee(route('admin.access-permissions.index'))
            ->assertSeeText('Доступ')
            ->assertDontSee(route('admin.users.index'))
            ->assertDontSee(route('admin.roles.index'));

        $rolesViewer = $this->actorWithPermissions(['roles.view']);
        $this->actingAs($rolesViewer)->get(route('home'))
            ->assertSee(route('admin.roles.index'))
            ->assertSeeText('Доступ')
            ->assertDontSee(route('admin.access-permissions.index'));

        $response = $this->actingAs(User::factory()->create())->get(route('home'));
        $response->assertDontSee(route('admin.access-permissions.index'))->assertSeeInOrder(['Сменить пароль', 'Выйти']);
    }

    public function test_exactly_two_web_routes_exist_and_update_is_not_get(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/access-permissions'));
        $this->assertCount(2, $routes);
        $this->assertTrue($routes->every(fn ($route) => ! str_starts_with($route->uri(), 'api/')));
        $this->assertNotContains('GET', app('router')->getRoutes()->getByName('admin.access-permissions.update')->methods());
    }
}
