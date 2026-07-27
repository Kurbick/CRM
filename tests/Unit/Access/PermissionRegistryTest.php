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
}
