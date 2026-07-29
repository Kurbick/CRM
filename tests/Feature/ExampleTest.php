<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Tests\AuthenticatedTestCase as TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        app(AccessControlSynchronizer::class)->sync();
        $this->authenticatedUser->givePermissionTo(PermissionName::DashboardView->value);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
