<?php

namespace Tests\Feature\Access;

use App\Actions\Admin\Users\AssignRoleToUser;
use App\Exceptions\LastAdministratorException;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_assignment_replaces_role_clears_direct_permissions_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findByName('dashboard.view'));
        $action = app(AssignRoleToUser::class);
        $action->handle($user, Role::findByName('viewer'));
        $action->handle($user, Role::findByName('accountant'));
        $action->handle($user, Role::findByName('accountant'));

        $this->assertSame(['accountant'], $user->fresh()->roles->pluck('name')->all());
        $this->assertCount(0, $user->fresh()->permissions);
    }

    public function test_last_active_administrator_is_protected_but_one_of_two_can_change_role(): void
    {
        $first = User::factory()->create();
        $first->assignRole('administrator');

        $this->expectException(LastAdministratorException::class);
        app(AssignRoleToUser::class)->handle($first, Role::findByName('viewer'));
    }

    public function test_one_of_two_active_administrators_can_change_role(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->assignRole('administrator');
        $second->assignRole('administrator');
        app(AssignRoleToUser::class)->handle($first, Role::findByName('viewer'));
        $this->assertTrue($first->fresh()->hasRole('viewer'));
    }

    public function test_guard_mismatch_is_rejected_without_changes(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'api-role', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        try {
            app(AssignRoleToUser::class)->handle($user, $role);
            $this->fail('Expected guard mismatch.');
        } catch (InvalidArgumentException) {
            $this->assertCount(0, $user->fresh()->roles);
        }
    }
}
