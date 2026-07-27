<?php

namespace Tests\Feature\Admin\AccessPermissions;

use Spatie\Permission\Models\Permission;

class UnknownPermissionPreservationTest extends AccessPermissionAdministrationTestCase
{
    public function test_unknown_permission_is_read_only_and_preserved_across_updates(): void
    {
        $actor = $this->actorWithPermissions(['access_permissions.view', 'access_permissions.update']);
        $role = $this->customRole();
        $otherRole = $this->customRole('Другая группа');
        $unknown = Permission::query()->create(['name' => 'legacy.special_access', 'guard_name' => 'web']);
        $role->givePermissionTo($unknown);
        $otherRole->givePermissionTo('dashboard.view');

        $response = $this->actingAs($actor)->get(route('admin.access-permissions.index', ['role' => $role->id]));
        $response->assertSeeText('У группы есть права, отсутствующие в текущем каталоге.')
            ->assertSeeText($unknown->name)
            ->assertDontSee('value="'.$unknown->name.'"', false);

        $this->put(route('admin.access-permissions.update', $role), ['permissions' => ['companies.view']])->assertRedirect();
        $this->put(route('admin.access-permissions.update', $role), ['permissions' => ['payments.view']])->assertRedirect();
        $this->assertEqualsCanonicalizing([$unknown->name, 'payments.view'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertDatabaseHas('permissions', ['id' => $unknown->id]);
        $this->assertSame(['dashboard.view'], $otherRole->fresh()->permissions->pluck('name')->all());
    }
}
