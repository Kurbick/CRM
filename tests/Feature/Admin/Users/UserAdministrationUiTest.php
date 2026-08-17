<?php

namespace Tests\Feature\Admin\Users;

use App\Models\Role;
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
        $this->get(route('admin.users.index'))
            ->assertSee('data-user-role-badge', false)->assertSee('data-user-status-badge', false)->assertSeeText('Только просмотр')->assertSeeText('Без группы')
            ->assertSee('method="GET" action="'.route('admin.users.index').'"', false)
            ->assertSeeText('Найти')->assertDontSeeText('Применить')
            ->assertSee('data-row-url=', false)->assertDontSee('>Открыть<', false);
        $creator = $this->actorWithPermissions(['users.view', 'users.create', 'users.assign_role']);
        $this->actingAs($creator)->get(route('admin.users.index'))->assertSee('Добавить пользователя');
        $this->get(route('admin.users.create'))
            ->assertSee('data-user-create-form', false)->assertSee('type="password"', false)->assertSee('autocomplete="new-password"', false)
            ->assertSeeText('Основная информация')->assertSeeText('Доступ')->assertSeeText('Не менее 12 символов')->assertDontSee('value="Strong', false)
            ->assertSee('href="'.route('admin.users.index').'"', false);
    }

    public function test_edit_has_separate_forms_methods_and_no_delete(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole('viewer');
        $response = $this->actingAs($admin)->get(route('admin.users.edit', $target));
        $response->assertSeeText('Основная информация')->assertSeeText('Группа')->assertSeeText('Статус учётной записи')->assertSeeText('Временный пароль')->assertSeeText('Последний вход')
            ->assertSee(route('admin.users.update', $target))->assertSee(route('admin.users.role.update', $target))->assertSee(route('admin.users.deactivate', $target))->assertSee(route('admin.users.password.update', $target))
            ->assertSee('name="_method" value="PUT"', false)->assertSee('name="_method" value="PATCH"', false)->assertDontSee('Удалить пользователя')
            ->assertSee('data-user-details', false)->assertSee('data-user-role', false)->assertSee('data-user-status', false)->assertSee('data-user-security', false)
            ->assertDontSee('data-accordion-section=', false)->assertDontSee('aria-controls="user-', false)->assertDontSee('value="Strong', false)
            ->assertSee('href="'.route('admin.users.edit', $target).'"', false);
    }

    public function test_empty_last_login_uses_the_same_copy_as_the_users_index(): void
    {
        $viewer = $this->actorWithPermissions(['users.view']);
        $target = User::factory()->create(['last_login_at' => null]);

        $this->actingAs($viewer)->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Не входил')
            ->assertDontSee('Никогда');
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
            ->assertSeeText('Для своей учётной записи используйте пункт')->assertSee(route('password.change'))
            ->assertSee('data-user-self-restriction class="text-sm text-gray-500"', false)
            ->assertDontSee('data-user-self-restriction class="text-sm text-amber-700"', false);
    }

    public function test_create_and_edit_forms_preserve_old_input(): void
    {
        $creator = $this->actorWithPermissions(['users.create', 'users.assign_role']);
        $role = Role::findByName('viewer');

        $this->actingAs($creator)->withSession(['_old_input' => [
            'name' => 'Новое имя',
            'email' => 'new@example.test',
            'role_id' => $role->id,
        ]])->get(route('admin.users.create'))
            ->assertSee('value="Новое имя"', false)->assertSee('value="new@example.test"', false)
            ->assertSee('value="'.$role->id.'" selected', false);

        $editor = $this->actorWithPermissions(['users.view', 'users.update']);
        $target = User::factory()->create();
        $this->actingAs($editor)->withSession(['_old_input' => [
            '_section' => 'user',
            'name' => 'Исправленное имя',
            'email' => 'edited@example.test',
        ]])->get(route('admin.users.edit', $target))
            ->assertSee('userOpen: true', false)->assertSee('value="Исправленное имя"', false)
            ->assertSee('value="edited@example.test"', false);
    }

    public function test_logout_remains_post_form(): void
    {
        $this->actingAs($this->administrator())->get(route('admin.users.index'))->assertSee('method="POST" action="'.route('logout').'"', false);
    }

    public function test_validation_errors_open_only_their_compact_form(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create();
        $target->assignRole('viewer');
        $this->actingAs($admin)->withSession(['_old_input' => ['_section' => 'user']])->get(route('admin.users.edit', $target))
            ->assertSee('userOpen: true', false)->assertSee('roleOpen: false', false);
        $this->withSession(['_old_input' => ['_section' => 'role']])->get(route('admin.users.edit', $target))
            ->assertSee('roleOpen: true', false);
        $this->withSession(['_old_input' => ['_section' => 'password']])->get(route('admin.users.edit', $target))
            ->assertSee('passwordOpen: true', false);
    }

    public function test_status_error_is_rendered_in_the_account_section(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->withSession(['error' => 'Нельзя отключить пользователя.'])->get(route('admin.users.edit', $admin))
            ->assertSee('data-user-status', false)->assertSeeText('Нельзя отключить пользователя.');
    }
}
