<?php

namespace Tests\Feature\Admin\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserStatusTest extends UserAdministrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
    }

    public function test_deactivation_revokes_target_access_state_only(): void
    {
        $actor = $this->actorWithPermissions(['users.deactivate']);
        $target = User::factory()->create(['remember_token' => 'old-token']);
        $target->assignRole('viewer');
        $other = User::factory()->create();
        $this->sessionRow('target-session', $target);
        $this->sessionRow('other-session', $other);
        $target->createToken('target');
        $other->createToken('other');

        $this->actingAs($actor)->patch(route('admin.users.deactivate', $target))->assertRedirect();
        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertTrue($target->hasRole('viewer'));
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertCount(0, $target->tokens);
        $this->assertCount(1, $other->tokens);
        $this->assertNotSame('old-token', $target->remember_token);
    }

    public function test_self_and_last_administrator_deactivation_are_blocked(): void
    {
        $actor = $this->actorWithPermissions(['users.deactivate']);
        $this->actingAs($actor)->patch(route('admin.users.deactivate', $actor))->assertSessionHasErrors('status');
        $admin = $this->administrator();
        $this->patch(route('admin.users.deactivate', $admin))->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_one_of_two_administrators_can_be_disabled(): void
    {
        $actor = $this->actorWithPermissions(['users.deactivate']);
        $first = $this->administrator();
        $this->administrator();
        $this->actingAs($actor)->patch(route('admin.users.deactivate', $first))->assertRedirect();
        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_activation_changes_only_status_and_requires_separate_permission(): void
    {
        $actor = $this->actorWithPermissions(['users.activate']);
        $target = User::factory()->inactive()->requiringPasswordChange()->create();
        $target->assignRole('viewer');
        $password = $target->password;
        $this->actingAs($actor)->patch(route('admin.users.activate', $target))->assertRedirect();
        $target->refresh();
        $this->assertTrue($target->is_active);
        $this->assertSame($password, $target->password);
        $this->assertTrue($target->must_change_password);
        $this->assertTrue($target->hasRole('viewer'));
        $this->assertCount(0, $target->tokens);
        $this->patch(route('admin.users.deactivate', $target))->assertForbidden();
    }

    private function sessionRow(string $id, User $user): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);
    }
}
