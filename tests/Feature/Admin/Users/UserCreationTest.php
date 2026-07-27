<?php

namespace Tests\Feature\Admin\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserCreationTest extends UserAdministrationTestCase
{
    public function test_user_is_created_with_required_authentication_state_and_role(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.assign_role']);
        $password = 'Strong-Temporary-123!';
        $response = $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => ' Новый пользователь ', 'email' => ' NEW@EXAMPLE.TEST ', 'role_id' => Role::findByName('viewer')->id,
            'password' => $password, 'password_confirmation' => $password,
        ]);
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $response->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('success')->assertDontSee($password)->assertDontSee($user->password);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertNull($user->last_login_at);
        $this->assertSame($actor->id, $user->created_by);
        $this->assertSame(['viewer'], $user->roles->pluck('name')->all());
        $this->assertCount(0, $user->permissions);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_password_email_and_role_validation_is_strict(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'users.assign_role']);
        User::factory()->create(['email' => 'duplicate@example.test']);
        $apiRole = Role::query()->create(['name' => 'api', 'guard_name' => 'sanctum', 'display_name' => 'API']);
        $this->actingAs($actor)->post(route('admin.users.store'), ['name' => 'X', 'email' => ' DUPLICATE@EXAMPLE.TEST ', 'role_id' => $apiRole->id, 'password' => 'weak', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors(['email', 'role_id', 'password']);
        $this->assertDatabaseMissing('users', ['name' => 'X']);
    }

    public function test_both_create_and_assign_role_permissions_are_required(): void
    {
        $actor = $this->actorWithPermissions(['users.create']);
        $this->actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
        $this->post(route('admin.users.store'), [])->assertForbidden();
    }
}
