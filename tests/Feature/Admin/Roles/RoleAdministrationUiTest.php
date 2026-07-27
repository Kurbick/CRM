<?php

namespace Tests\Feature\Admin\Roles;

use App\Models\User;

class RoleAdministrationUiTest extends RoleAdministrationTestCase
{
    public function test_index_renders_compact_accessible_accordions_and_counts(): void
    {
        $admin = $this->administrator();
        $custom = $this->customRole();
        User::factory()->create()->assignRole($custom);

        $response = $this->actingAs($admin)->get(route('admin.roles.index'));
        $response->assertSeeText('Настройка групп пользователей и их назначения в CRM.')
            ->assertSee('data-create-role-accordion', false)
            ->assertSee('aria-controls="create-role-content"', false)
            ->assertSee('data-role-accordion=', false)
            ->assertSee('aria-controls="role-'.$custom->id.'-content"', false)
            ->assertSee('id="role-'.$custom->id.'-content"', false)
            ->assertSee('x-bind:aria-expanded="open.toString()"', false)
            ->assertSee('x-cloak', false)
            ->assertSeeText('Администратор')->assertSeeText($custom->display_name)
            ->assertDontSee('data-role-type-badge', false)
            ->assertSeeText('Системная группа')->assertSeeText('Пользовательская группа')
            ->assertSeeText('Пользователей: 1')->assertSeeText('Прав: 43')
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="guard_name"', false)
            ->assertDontSee('name="is_system"', false)
            ->assertDontSee('name="sort_order"', false);
    }

    public function test_delete_forms_follow_role_type_assignment_and_permission(): void
    {
        $actor = $this->actorWithPermissions(['roles.view', 'roles.delete']);
        $empty = $this->customRole('Пустая группа');
        $assigned = $this->customRole('Назначенная группа');
        User::factory()->create()->assignRole($assigned);
        $response = $this->actingAs($actor)->get(route('admin.roles.index'));

        $response->assertSeeText('Системную группу нельзя удалить.')
            ->assertSeeText('Группа назначена пользователям и не может быть удалена.')
            ->assertSee(route('admin.roles.destroy', $empty))
            ->assertDontSee(route('admin.roles.destroy', $assigned));
        $this->assertSame(1, substr_count($response->getContent(), 'name="_method" value="DELETE"'));
    }

    public function test_metadata_is_read_only_without_update_permission(): void
    {
        $actor = $this->actorWithPermissions(['roles.view']);
        $response = $this->actingAs($actor)->get(route('admin.roles.index'));
        $response->assertDontSee('Сохранить изменения')->assertDontSee('name="_method" value="PUT"', false);
    }

    public function test_validation_errors_open_only_the_relevant_accordion(): void
    {
        $actor = $this->actorWithPermissions(['roles.view', 'roles.create']);
        $first = $this->customRole('Первая');
        $second = $this->customRole('Вторая');

        $this->actingAs($actor)->withSession(['_old_input' => ['_section' => 'create']])->get(route('admin.roles.index'))
            ->assertSee('data-create-role-accordion data-initial-open="true"', false);
        $this->withSession(['_old_input' => ['_section' => 'role-'.$first->id]])->get(route('admin.roles.index'))
            ->assertSee('data-role-accordion="'.$first->id.'" data-initial-open="true"', false)
            ->assertSee('data-role-accordion="'.$second->id.'" data-initial-open="false"', false);
    }
}
