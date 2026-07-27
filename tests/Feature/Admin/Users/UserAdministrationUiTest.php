<?php

namespace Tests\Feature\Admin\Users;

use App\Models\User;

class UserAdministrationUiTest extends UserAdministrationTestCase
{
    public function test_index_and_create_have_expected_structure_and_permissions(): void
    {
        $viewer = $this->actorWithPermissions(['users.view']);
        $withRole = User::factory()->create();
        $withRole->assignRole('viewer');
        User::factory()->create();
        $this->actingAs($viewer)->get(route('admin.users.index'))->assertSeeText('Управление внутренними учётными записями и доступом к CRM.')->assertSeeText('Пользователь')->assertDontSee('Добавить пользователя');
        $this->get(route('admin.users.index'))->assertSee('data-user-role-badge', false)->assertSee('data-user-status-badge', false)->assertSeeText('Только просмотр')->assertSeeText('Без группы');
        $creator = $this->actorWithPermissions(['users.view', 'users.create', 'users.assign_role']);
        $this->actingAs($creator)->get(route('admin.users.index'))->assertSee('Добавить пользователя');
        $this->get(route('admin.users.create'))->assertSee('type="password"', false)->assertSee('autocomplete="new-password"', false)->assertSeeText('Не менее 12 символов')->assertDontSee('value="Strong', false);
    }

    public function test_edit_has_separate_forms_methods_and_no_delete(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole('viewer');
        $response = $this->actingAs($admin)->get(route('admin.users.edit', $target));
        $response->assertSeeText('Основные данные')->assertSeeText('Группа')->assertSeeText('Статус учётной записи')->assertSeeText('Временный пароль')
            ->assertSee(route('admin.users.update', $target))->assertSee(route('admin.users.role.update', $target))->assertSee(route('admin.users.deactivate', $target))->assertSee(route('admin.users.password.update', $target))
            ->assertSee('name="_method" value="PUT"', false)->assertSee('name="_method" value="PATCH"', false)->assertDontSee('Удалить пользователя')
            ->assertSee('aria-controls="user-details-content"', false)->assertSee('aria-controls="user-role-content"', false)->assertSee('aria-controls="user-status-content"', false)->assertSee('aria-controls="user-password-content"', false)
            ->assertSee('x-bind:aria-expanded="open.toString()"', false)->assertSee('id="user-details-content"', false)->assertSee('id="user-role-content"', false)->assertSee('id="user-status-content"', false)->assertSee('id="user-password-content"', false)
            ->assertSee('data-initial-open="false"', false)->assertSee('x-cloak', false)->assertDontSee('value="Strong', false);
        $this->assertSame(4, substr_count($response->getContent(), 'data-accordion-section='));
    }

    public function test_read_only_and_self_explanations_are_rendered(): void
    {
        $readOnly = $this->actorWithPermissions(['users.view']);
        $target = User::factory()->create();
        $this->actingAs($readOnly)->get(route('admin.users.edit', $target))->assertDontSee('Сохранить данные')->assertDontSee('Обновить группу')->assertDontSee('Установить временный пароль');
        $admin = $this->administrator();
        $this->actingAs($admin)->get(route('admin.users.edit', $admin))
            ->assertSeeText('Изменить собственную группу может другой администратор.')
            ->assertSeeText('Отключить собственную учётную запись может другой администратор.')
            ->assertSeeText('Для своей учётной записи используйте пункт')->assertSee(route('password.change'));
    }

    public function test_logout_remains_post_form(): void
    {
        $this->actingAs($this->administrator())->get(route('admin.users.index'))->assertSee('method="POST" action="'.route('logout').'"', false);
    }

    public function test_validation_errors_open_only_their_accordion_section(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole('viewer');
        $this->actingAs($admin)->withSession(['_old_input' => ['_section' => 'user']])->get(route('admin.users.edit', $target))
            ->assertSee('data-accordion-section="user" data-initial-open="true"', false)->assertSee('data-accordion-section="role" data-initial-open="false"', false);
        $this->withSession(['_old_input' => ['_section' => 'role']])->get(route('admin.users.edit', $target))
            ->assertSee('data-accordion-section="role" data-initial-open="true"', false);
        $this->withSession(['_old_input' => ['_section' => 'password']])->get(route('admin.users.edit', $target))
            ->assertSee('data-accordion-section="password" data-initial-open="true"', false);
    }

    public function test_status_error_opens_status_section(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->withSession(['error' => 'Нельзя отключить пользователя.'])->get(route('admin.users.edit', $admin))
            ->assertSee('data-accordion-section="status" data-initial-open="true"', false);
    }
}
