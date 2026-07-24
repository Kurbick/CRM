<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_crm_routes_force_password_change_without_loop(): void
    {
        $user = User::factory()->requiringPasswordChange()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('password.change'));
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('companies.index'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertOk()->assertSee('Для продолжения работы');
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_api_returns_stable_password_change_required_code(): void
    {
        Sanctum::actingAs(User::factory()->requiringPasswordChange()->create());

        $this->getJson(route('api.dashboard'))->assertForbidden()->assertJson([
            'code' => 'password_change_required',
        ]);
    }
}
