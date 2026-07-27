<?php

namespace Tests\Feature\Admin\Roles;

use App\Actions\Admin\Roles\UpdateRole;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;

class RoleUpdateTest extends RoleAdministrationTestCase
{
    public function test_update_changes_only_metadata_for_custom_role(): void
    {
        $actor = $this->actorWithPermissions(['roles.update']);
        $role = $this->customRole();
        $role->syncPermissions(['dashboard.view']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $unchanged = [$role->name, $role->guard_name, $role->is_system, $role->sort_order];

        $this->actingAs($actor)->put(route('admin.roles.update', $role), ['display_name' => ' Новое название ', 'description' => '   '])->assertRedirect()->assertSessionHas('success');
        $role->refresh();
        $this->assertSame('Новое название', $role->display_name);
        $this->assertNull($role->description);
        $this->assertSame($unchanged, [$role->name, $role->guard_name, $role->is_system, $role->sort_order]);
        $this->assertSame(['dashboard.view'], $role->permissions->pluck('name')->all());
        $this->assertTrue($user->fresh()->hasRole($role));
    }

    public function test_system_metadata_survives_foundation_sync_and_custom_role_remains(): void
    {
        $actor = $this->actorWithPermissions(['roles.update']);
        $system = Role::findByName('accountant');
        $custom = $this->customRole();
        $this->actingAs($actor)->put(route('admin.roles.update', $system), ['display_name' => 'Финансовая группа', 'description' => 'Ручное описание'])->assertRedirect();

        app(AccessControlSynchronizer::class)->sync();
        $this->assertSame('Финансовая группа', $system->fresh()->display_name);
        $this->assertSame('Ручное описание', $system->fresh()->description);
        $this->assertTrue(Role::query()->whereKey($custom->id)->exists());
    }

    public function test_duplicate_non_web_and_missing_permission_are_rejected(): void
    {
        $actor = $this->actorWithPermissions(['roles.update']);
        $first = $this->customRole('Первая');
        $second = $this->customRole('Вторая');
        $this->actingAs($actor)->put(route('admin.roles.update', $second), ['display_name' => ' первая ', 'description' => null])
            ->assertSessionHasErrorsIn('updateRole-'.$second->id, 'display_name');

        $apiRole = Role::query()->create(['name' => 'api-role', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        $this->put(route('admin.roles.update', $apiRole), ['display_name' => 'API 2', 'description' => null])->assertNotFound();

        $this->expectException(\InvalidArgumentException::class);
        app(UpdateRole::class)->handle($apiRole, ['display_name' => 'API 2', 'description' => null]);
    }

    public function test_user_without_update_permission_is_forbidden(): void
    {
        $role = $this->customRole();
        $this->actingAs($this->actorWithPermissions(['roles.view']))->put(route('admin.roles.update', $role), [])->assertForbidden();
    }

    public function test_success_message_is_consumed_after_the_groups_page(): void
    {
        $actor = $this->actorWithPermissions(['roles.view', 'roles.update']);
        $role = $this->customRole();

        $this->actingAs($actor)->put(route('admin.roles.update', $role), ['display_name' => 'Обновлённая группа', 'description' => null])
            ->assertRedirect(route('admin.roles.index'));
        $this->get(route('admin.roles.index'))->assertSeeText('Данные группы обновлены.');
        $this->get(route('dashboard'))->assertDontSeeText('Данные группы обновлены.');
    }
}
