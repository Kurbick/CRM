<?php

namespace Tests\Unit\Access;

use App\Support\Access\PermissionName;
use App\Support\Access\PermissionRegistry;
use PHPUnit\Framework\TestCase;

class PermissionRegistryTest extends TestCase
{
    public function test_registry_is_complete_unique_and_deterministic(): void
    {
        $items = PermissionRegistry::all();
        $names = PermissionRegistry::names();

        $this->assertCount(43, $items);
        $this->assertSame(array_map(fn (PermissionName $name) => $name->value, PermissionName::cases()), $names);
        $this->assertCount(count($names), array_unique($names));
        $this->assertTrue(collect($items)->every(fn (array $item) => $item['label'] !== '' && $item['module'] !== '' && $item['module_label'] !== ''));
        $this->assertSame($names, PermissionRegistry::names());
        $this->assertTrue(collect($names)->every(fn (string $name) => preg_match('/^[a-z_]+\.[a-z_]+(?:\.[a-z_]+)?$/', $name) === 1));
    }

    public function test_grouped_registry_preserves_complete_catalog_and_order(): void
    {
        $groups = PermissionRegistry::grouped();
        $flattened = collect($groups)->flatMap(fn (array $group) => array_map(
            fn (array $permission) => $permission['name']->value,
            $group['permissions'],
        ))->values()->all();

        $this->assertCount(11, $groups);
        $this->assertSame(PermissionRegistry::names(), $flattened);
        $this->assertTrue(collect($groups)->every(fn (array $group) => $group['module'] !== '' && $group['label'] !== '' && $group['permissions'] !== []));
        $this->assertSame(collect($groups)->pluck('order')->sort()->values()->all(), collect($groups)->pluck('order')->all());
        $access = collect($groups)->firstWhere('module', 'access_permissions');
        $this->assertSame('Права доступа', $access['label']);
        $this->assertSame(['access_permissions.view', 'access_permissions.update'], array_map(fn (array $permission) => $permission['name']->value, $access['permissions']));
    }
}
