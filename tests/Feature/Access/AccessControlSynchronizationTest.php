<?php

namespace Tests\Feature\Access;

use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionRegistry;
use App\Support\Access\SystemRoleRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccessControlSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_complete_foundation_and_is_idempotent_without_destructive_cleanup(): void
    {
        $sync = app(AccessControlSynchronizer::class);
        $sync->sync();

        $this->assertSame(count(PermissionRegistry::names()), Permission::query()->where('guard_name', 'web')->count());
        $this->assertSame(3, Role::query()->where('is_system', true)->count());
        $this->assertInstanceOf(Role::class, Role::findByName('administrator'));

        foreach (SystemRoleRegistry::all() as $definition) {
            $role = Role::findByName($definition['name']->value);
            $this->assertSame($definition['display_name'], $role->display_name);
            $this->assertSame($definition['sort_order'], $role->sort_order);
            $this->assertEqualsCanonicalizing(array_map(fn ($p) => $p->value, $definition['permissions']), $role->permissions->pluck('name')->all());
        }

        $accountant = Role::findByName('accountant');
        $accountant->syncPermissions(['dashboard.view']);
        $accountant->update(['display_name' => 'Настроенный бухгалтер']);
        Role::query()->create(['name' => 'custom', 'guard_name' => 'web', 'display_name' => 'Custom']);
        Permission::query()->create(['name' => 'legacy.permission', 'guard_name' => 'web']);
        Role::findByName('administrator')->syncPermissions([]);

        $sync->sync();
        $this->assertSame(['dashboard.view'], $accountant->fresh()->permissions->pluck('name')->all());
        $this->assertSame('Настроенный бухгалтер', $accountant->fresh()->display_name);
        $this->assertTrue(Role::query()->where('name', 'custom')->exists());
        $this->assertTrue(Permission::query()->where('name', 'legacy.permission')->exists());
        $this->assertEqualsCanonicalizing(PermissionRegistry::names(), Role::findByName('administrator')->permissions->pluck('name')->all());
    }

    public function test_database_seeder_does_not_create_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, User::query()->count());
    }
}
