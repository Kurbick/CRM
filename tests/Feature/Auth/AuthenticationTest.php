<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_login_and_active_user_can_login(): void
    {
        $user = User::factory()->create(['email' => 'USER@EXAMPLE.COM']);
        $updatedAt = $user->updated_at;

        $this->get(route('login'))->assertOk()->assertSee('>Вход</h1>', false);
        $this->post(route('login.store'), [
            'email' => 'user@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue($updatedAt->equalTo($user->fresh()->updated_at));
    }

    public function test_unknown_bad_password_and_inactive_user_share_generic_failure(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        foreach ([
            ['email' => 'missing@example.com', 'password' => 'wrong'],
            ['email' => $active->email, 'password' => 'wrong'],
            ['email' => $inactive->email, 'password' => 'password'],
        ] as $credentials) {
            $this->post(route('login.store'), $credentials)
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->assertNull($active->fresh()->last_login_at);
        $this->assertNull($inactive->fresh()->last_login_at);
    }

    public function test_login_regenerates_session_and_supports_remember(): void
    {
        $user = User::factory()->create();
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    public function test_successful_login_uses_intended_url(): void
    {
        $user = User::factory()->create();
        $this->get(route('companies.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('companies.index'));
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => 'limit@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => 'limit@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_logout_is_post_only_and_ends_authentication(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/logout')->assertMethodNotAllowed();
    }
}
