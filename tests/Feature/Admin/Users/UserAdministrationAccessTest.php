<?php

namespace Tests\Feature\Admin\Users;

use App\Models\User;

class UserAdministrationAccessTest extends UserAdministrationTestCase
{
    public function test_guest_inactive_and_unprivileged_users_cannot_access(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $inactive = User::factory()->inactive()->create();
        $this->actingAs($inactive)->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_users_view_allows_reading_but_not_mutations(): void
    {
        $actor = $this->actorWithPermissions(['users.view']);
        $target = User::factory()->create();
        $this->actingAs($actor)->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.users.edit', $target))->assertOk();
        $this->put(route('admin.users.update', $target), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
    }

    public function test_administrator_accesses_pages_via_gate_before(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    }

    public function test_settings_link_and_dividers_follow_users_view(): void
    {
        $withAccess = $this->actorWithPermissions(['users.view']);
        $this->actingAs($withAccess)->get(route('dashboard'))->assertSee('Пользователи')->assertSee(route('admin.users.index'));
        $withoutAccess = User::factory()->create();
        $response = $this->actingAs($withoutAccess)->get(route('dashboard'));
        $response->assertDontSee(route('admin.users.index'));
        $response->assertSeeInOrder(['Сменить пароль', 'Выйти']);
    }

    public function test_admin_routes_are_web_only_and_mutations_have_no_get_routes(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $admin = $routes->filter(fn ($route) => str_starts_with($route->uri(), 'admin/users'));
        $this->assertCount(9, $admin);
        $this->assertTrue($admin->every(fn ($route) => ! str_starts_with($route->uri(), 'api/')));
        foreach (['admin.users.store', 'admin.users.update', 'admin.users.role.update', 'admin.users.activate', 'admin.users.deactivate', 'admin.users.password.update'] as $name) {
            $this->assertNotContains('GET', app('router')->getRoutes()->getByName($name)->methods());
        }
    }
}
