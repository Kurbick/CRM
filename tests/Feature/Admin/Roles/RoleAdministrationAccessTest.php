<?php

namespace Tests\Feature\Admin\Roles;

use App\Models\User;

class RoleAdministrationAccessTest extends RoleAdministrationTestCase
{
    public function test_guest_inactive_and_unprivileged_users_cannot_access(): void
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->inactive()->create())->get(route('admin.roles.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_roles_view_allows_index_but_not_mutations(): void
    {
        $actor = $this->actorWithPermissions(['roles.view']);
        $role = $this->customRole();
        $this->actingAs($actor)->get(route('admin.roles.index'))->assertOk();
        $this->post(route('admin.roles.store'), [])->assertForbidden();
        $this->put(route('admin.roles.update', $role), [])->assertForbidden();
        $this->delete(route('admin.roles.destroy', $role))->assertForbidden();
    }

    public function test_administrator_accesses_index_via_registry_gate_before(): void
    {
        $this->actingAs($this->administrator())->get(route('admin.roles.index'))->assertOk();
    }

    public function test_settings_links_and_dividers_are_independent(): void
    {
        $rolesViewer = $this->actorWithPermissions(['roles.view']);
        $this->actingAs($rolesViewer)->get(route('home'))->assertSee(route('admin.roles.index'))->assertDontSee(route('admin.users.index'));

        $usersViewer = $this->actorWithPermissions(['users.view']);
        $this->actingAs($usersViewer)->get(route('home'))->assertSee(route('admin.users.index'))->assertDontSee(route('admin.roles.index'));

        $response = $this->actingAs(User::factory()->create())->get(route('home'));
        $response->assertDontSee(route('admin.roles.index'))->assertDontSee(route('admin.users.index'))->assertSeeInOrder(['Сменить пароль', 'Выйти']);
    }

    public function test_exactly_four_web_routes_exist_and_mutations_are_not_get(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/groups'));
        $this->assertCount(4, $routes);
        $this->assertTrue($routes->every(fn ($route) => ! str_starts_with($route->uri(), 'api/')));

        foreach (['admin.roles.store', 'admin.roles.update', 'admin.roles.destroy'] as $name) {
            $this->assertNotContains('GET', app('router')->getRoutes()->getByName($name)->methods());
        }
    }
}
