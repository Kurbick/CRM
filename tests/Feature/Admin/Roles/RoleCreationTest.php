<?php

namespace Tests\Feature\Admin\Roles;

use App\Models\Role;
use App\Models\User;

class RoleCreationTest extends RoleAdministrationTestCase
{
    public function test_custom_role_is_created_with_generated_identity_and_no_assignments(): void
    {
        $actor = $this->actorWithPermissions(['roles.create']);
        $maxSort = Role::query()->where('guard_name', 'web')->max('sort_order');
        $response = $this->actingAs($actor)->post(route('admin.roles.store'), [
            'display_name' => ' Менеджер ',
            'description' => ' Работа с клиентами. ',
            'name' => 'administrator',
            'guard_name' => 'sanctum',
            'is_system' => true,
            'sort_order' => 1,
        ]);
        $role = Role::query()->where('display_name', 'Менеджер')->firstOrFail();

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertStringStartsWith('custom-', $role->name);
        $this->assertNotSame($role->display_name, $role->name);
        $this->assertSame('web', $role->guard_name);
        $this->assertFalse($role->is_system);
        $this->assertGreaterThan($maxSort, $role->sort_order);
        $this->assertSame('Работа с клиентами.', $role->description);
        $this->assertCount(0, $role->permissions);
        $this->assertCount(0, $role->users);
    }

    public function test_description_is_nullable_and_validation_is_strict(): void
    {
        $actor = $this->actorWithPermissions(['roles.create']);
        Role::query()->create(['name' => 'existing', 'guard_name' => 'web', 'display_name' => 'Менеджер']);

        $this->actingAs($actor)->post(route('admin.roles.store'), ['display_name' => ' менеджер ', 'description' => ''])
            ->assertSessionHasErrorsIn('createRole', 'display_name');
        $this->post(route('admin.roles.store'), ['display_name' => str_repeat('А', 256), 'description' => str_repeat('Б', 1001)])
            ->assertSessionHasErrorsIn('createRole', ['display_name', 'description']);
        $this->post(route('admin.roles.store'), ['display_name' => 'Без описания', 'description' => '   '])->assertRedirect();
        $this->assertNull(Role::query()->where('display_name', 'Без описания')->value('description'));
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $this->actingAs($this->actorWithPermissions(['roles.view']))->post(route('admin.roles.store'), [])->assertForbidden();
    }

    public function test_success_message_is_consumed_after_the_groups_page(): void
    {
        $actor = $this->actorWithPermissions(['roles.view', 'roles.create']);

        $this->actingAs($actor)->post(route('admin.roles.store'), ['display_name' => 'Новая группа', 'description' => null])
            ->assertRedirect(route('admin.roles.index'));
        $this->get(route('admin.roles.index'))->assertSeeText('Группа создана.');
        $this->get(route('home'))->assertDontSeeText('Группа создана.');
    }

    public function test_new_role_appears_in_user_administration_forms(): void
    {
        $role = $this->customRole('Менеджер проектов');
        $admin = $this->administrator();
        $target = User::factory()->create();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertSeeText($role->display_name);
        $this->get(route('admin.users.edit', $target))->assertSeeText($role->display_name);
    }
}
