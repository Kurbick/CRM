<?php

namespace Tests\Feature\Admin\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserPasswordResetTest extends UserAdministrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
    }

    public function test_reset_updates_only_password_state_and_revokes_access(): void
    {
        $actor = $this->actorWithPermissions(['users.reset_password']);
        $target = User::factory()->create(['password' => 'Old-Password-123!', 'last_login_at' => now()->subDay(), 'remember_token' => 'old-token', 'is_active' => false]);
        $target->assignRole('viewer');
        $other = User::factory()->create();
        $this->sessionRow('target-session', $target);
        $this->sessionRow('other-session', $other);
        $target->createToken('target');
        $other->createToken('other');
        $new = 'New-Temporary-456!';

        $response = $this->actingAs($actor)->put(route('admin.users.password.update', $target), ['password' => $new, 'password_confirmation' => $new]);
        $response->assertRedirect()->assertDontSee($new);
        $target->refresh();
        $this->assertTrue(Hash::check($new, $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertNull($target->password_changed_at);
        $this->assertFalse($target->is_active);
        $this->assertTrue($target->hasRole('viewer'));
        $this->assertNotNull($target->last_login_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertCount(0, $target->tokens);
        $this->assertCount(1, $other->tokens);
        $this->assertNotSame('old-token', $target->remember_token);
        $this->assertCount(0, $target->permissions);
    }

    public function test_weak_mismatched_and_self_reset_are_rejected(): void
    {
        $actor = $this->actorWithPermissions(['users.reset_password']);
        $target = User::factory()->create();
        $this->actingAs($actor)->put(route('admin.users.password.update', $target), ['password' => 'weak', 'password_confirmation' => 'different'])->assertSessionHasErrorsIn('resetPassword', 'password');
        $this->put(route('admin.users.password.update', $actor), ['password' => 'Strong-Self-123!', 'password_confirmation' => 'Strong-Self-123!'])->assertSessionHasErrorsIn('resetPassword', 'password');
    }

    public function test_new_password_login_requires_mandatory_change_and_old_password_fails(): void
    {
        config(['session.driver' => 'array']);
        $actor = $this->actorWithPermissions(['users.reset_password']);
        $target = User::factory()->create(['email' => 'target@example.test', 'password' => 'Old-Password-123!']);
        $new = 'New-Temporary-456!';
        $this->actingAs($actor)->put(route('admin.users.password.update', $target), ['password' => $new, 'password_confirmation' => $new]);
        auth()->logout();
        $this->post(route('login.store'), ['email' => $target->email, 'password' => 'Old-Password-123!'])->assertSessionHasErrors();
        $this->post(route('login.store'), ['email' => $target->email, 'password' => $new])->assertRedirect(route('password.change'));
    }

    private function sessionRow(string $id, User $user): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);
    }
}
