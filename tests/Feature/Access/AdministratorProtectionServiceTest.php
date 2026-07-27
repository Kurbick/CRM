<?php

namespace Tests\Feature\Access;

use App\Exceptions\LastAdministratorException;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Services\AdministratorProtectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministratorProtectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_last_active_administrator_cannot_be_deactivated_or_changed(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $service = app(AdministratorProtectionService::class);

        foreach (['deactivate', 'change'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'deactivate'
                    ? $service->assertCanDeactivate($admin)
                    : $service->assertCanChangeRole($admin, Role::findByName('viewer')));
                $this->fail('Protection exception was expected.');
            } catch (LastAdministratorException $exception) {
                $this->assertSame('Нельзя отключить или лишить группы последнего активного администратора.', $exception->getMessage());
            }
        }

        DB::transaction(fn () => $service->assertCanChangeRole($admin, Role::findByName('administrator')));
    }

    public function test_second_active_admin_allows_operation_and_inactive_admin_is_not_protected(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $inactive = User::factory()->inactive()->create();
        $first->assignRole('administrator');
        $second->assignRole('administrator');
        $inactive->assignRole('administrator');

        DB::transaction(fn () => app(AdministratorProtectionService::class)->assertCanDeactivate($first));
        DB::transaction(fn () => app(AdministratorProtectionService::class)->assertCanDeactivate($inactive));
        $this->assertTrue(true);
    }
}
