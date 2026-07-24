<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_sanctum_authentication(): void
    {
        $this->getJson(route('api.dashboard'))->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson(route('api.dashboard'))->assertOk();
    }
}
