<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_sanctum_authentication(): void
    {
        $this->getJson(route('api.dashboard'))->assertUnauthorized();

        app(AccessControlSynchronizer::class)->sync();
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::DashboardView->value);
        Sanctum::actingAs($user);
        $this->getJson(route('api.dashboard'))->assertOk();
    }
}
