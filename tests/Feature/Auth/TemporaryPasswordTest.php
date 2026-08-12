<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_crm_routes_force_password_change_without_loop(): void
    {
        $user = User::factory()->requiringPasswordChange()->create();

        $this->withSession(['url.intended' => route('companies.index')])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('password.change'));
        $this->assertNull(session()->get('url.intended'));
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('companies.index'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('Для продолжения работы')
            ->assertDontSee('name="current_password"', false);
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_forced_password_change_replaces_temporary_password_and_allows_normal_login(): void
    {
        $user = User::factory()->requiringPasswordChange()->create([
            'email' => 'first-login@example.test',
            'password' => Hash::make('Temporary!Password12'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Temporary!Password12',
        ])->assertRedirect(route('password.change'));

        $this->put(route('user-password.update'), [
            'password' => 'Permanent!Password12',
            'password_confirmation' => 'Permanent!Password12',
        ])->assertRedirect(route('home'));

        $this->assertFalse($user->fresh()->mustChangePassword());
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Temporary!Password12',
        ])->assertSessionHasErrors('email');
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Permanent!Password12',
        ])->assertRedirect(route('home'));
    }

    public function test_forced_password_confirmation_mismatch_is_rejected_without_clearing_state(): void
    {
        $user = User::factory()->requiringPasswordChange()->create();
        $this->actingAs($user);

        $this->put(route('user-password.update'), [
            'password' => 'Permanent!Password12',
            'password_confirmation' => 'Different!Password12',
        ])->assertSessionHasErrorsIn('updatePassword', [
            'password_confirmation' => 'Пароли не совпадают.',
        ]);

        $this->assertTrue($user->fresh()->mustChangePassword());
    }

    public function test_api_returns_stable_password_change_required_code(): void
    {
        Sanctum::actingAs(User::factory()->requiringPasswordChange()->create());

        $this->getJson(route('api.dashboard'))->assertForbidden()->assertJson([
            'code' => 'password_change_required',
        ]);
    }
}
