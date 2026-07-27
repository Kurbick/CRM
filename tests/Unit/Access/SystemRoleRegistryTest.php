<?php

namespace Tests\Unit\Access;

use App\Support\Access\PermissionRegistry;
use App\Support\Access\SystemRole;
use App\Support\Access\SystemRoleRegistry;
use PHPUnit\Framework\TestCase;

class SystemRoleRegistryTest extends TestCase
{
    public function test_system_roles_and_defaults_are_valid(): void
    {
        $roles = SystemRoleRegistry::all();
        $this->assertSame(['administrator', 'accountant', 'viewer'], array_map(fn (array $role) => $role['name']->value, $roles));
        $this->assertSame(['Администратор', 'Бухгалтер', 'Только просмотр'], array_column($roles, 'display_name'));

        foreach ([SystemRole::Accountant, SystemRole::Viewer] as $role) {
            foreach (SystemRoleRegistry::permissions($role) as $permission) {
                $this->assertContains($permission->value, PermissionRegistry::names());
            }
        }

        $viewer = array_map(fn ($permission) => $permission->value, SystemRoleRegistry::permissions(SystemRole::Viewer));
        $this->assertNotContains('companies.financials.view', $viewer);
    }
}
