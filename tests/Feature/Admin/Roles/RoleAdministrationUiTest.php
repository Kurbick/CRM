<?php

namespace Tests\Feature\Admin\Roles;

use App\Models\User;

class RoleAdministrationUiTest extends RoleAdministrationTestCase
{
    public function test_groups_url_redirects_to_access_workspace_when_permissions_are_visible(): void
    {
        $admin = $this->administrator();
        $custom = $this->customRole();
        User::factory()->create()->assignRole($custom);

        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertRedirect(route('admin.access-permissions.index'));
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
