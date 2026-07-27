<?php

namespace Tests\Feature\Access;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdministratorGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bypass_is_limited_to_active_administrator_and_registry_slugs(): void
    {
        app(AccessControlSynchronizer::class)->sync();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('administrator'));

        foreach (PermissionRegistry::names() as $permission) {
            $this->assertTrue($admin->can($permission));
        }
        $this->assertTrue($admin->can('invoices.update'));
        Gate::define('invoice-business-update', fn () => false);
        $this->assertFalse($admin->can('invoice-business-update'));
        $this->assertFalse($admin->can('unknown-ability'));

        Role::findByName('administrator')->revokePermissionTo('invoices.update');
        $inactiveAdmin = User::factory()->inactive()->create();
        $inactiveAdmin->assignRole('administrator');
        $this->assertFalse($inactiveAdmin->can('invoices.update'));
    }

    public function test_non_administrators_receive_only_role_permissions(): void
    {
        app(AccessControlSynchronizer::class)->sync();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $plain = User::factory()->create();

        $this->assertTrue($accountant->can('companies.financials.view'));
        $this->assertFalse($accountant->can('companies.delete'));
        $this->assertTrue($viewer->can('companies.view'));
        $this->assertFalse($viewer->can('companies.financials.view'));
        $this->assertFalse($plain->can('dashboard.view'));
    }
}
