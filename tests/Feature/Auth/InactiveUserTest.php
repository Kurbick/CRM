<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_inactive_web_user_is_logged_out(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Учётная запись отключена.');
        $this->assertGuest();
    }

    public function test_authenticated_inactive_api_user_receives_json_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->create());

        $this->getJson(route('api.dashboard'))->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }
}
