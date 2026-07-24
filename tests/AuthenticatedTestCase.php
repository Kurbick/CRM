<?php

namespace Tests;

use App\Models\User;

abstract class AuthenticatedTestCase extends TestCase
{
    protected User $authenticatedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->actingAs($this->authenticatedUser, 'web');
    }
}
