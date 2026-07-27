<?php

namespace Tests\Feature\Admin\Users;

use App\Models\User;

class UserIndexTest extends UserAdministrationTestCase
{
    public function test_index_displays_user_role_status_and_never(): void
    {
        $actor = $this->actorWithPermissions(['users.view']);
        $target = User::factory()->inactive()->create(['name' => 'Иван Тестовый', 'email' => 'ivan@example.test', 'last_login_at' => null]);
        $target->assignRole('viewer');
        $this->actingAs($actor)->get(route('admin.users.index'))
            ->assertOk()->assertSee('Иван Тестовый')->assertSee('ivan@example.test')->assertSee('Только просмотр')->assertSee('Отключён')->assertSee('Никогда');
    }

    public function test_search_status_and_role_filters_work(): void
    {
        $actor = $this->actorWithPermissions(['users.view']);
        $active = User::factory()->create(['name' => 'Альфа', 'email' => 'alpha@example.test']);
        $active->assignRole('viewer');
        User::factory()->inactive()->create(['name' => 'Бета', 'email' => 'beta@example.test']);

        $this->actingAs($actor)->get(route('admin.users.index', ['search' => '  alpha@example.test  ']))->assertSee('Альфа')->assertDontSee('Бета');
        $this->get(route('admin.users.index', ['status' => 'inactive']))->assertSee('Бета')->assertDontSee('Альфа');
        $this->get(route('admin.users.index', ['role' => $active->roles->first()->id]))->assertSee('Альфа')->assertDontSee('Бета');
        $this->get(route('admin.users.index', ['role' => 'none']))->assertSee('Бета')->assertDontSee('Альфа');
    }

    public function test_sorting_is_allowlisted_and_pagination_preserves_filters(): void
    {
        $actor = $this->actorWithPermissions(['users.view']);
        User::factory()->count(22)->create();
        $response = $this->actingAs($actor)->get(route('admin.users.index', ['search' => 'example', 'sort' => 'name', 'direction' => 'asc']));
        $response->assertOk()->assertSee('search=example', false);
        $this->get(route('admin.users.index', ['sort' => 'name desc; drop table users', 'direction' => 'sideways']))->assertOk();
        $this->assertTrue(User::query()->exists());
    }
}
