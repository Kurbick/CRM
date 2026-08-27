<?php

namespace Tests\Unit;

use Tests\TestCase;

class ContractIndexViewTest extends TestCase
{
    public function test_status_filter_uses_the_invoice_custom_dropdown_pattern(): void
    {
        $source = file_get_contents(resource_path('views/contracts/index.blade.php'));

        $this->assertStringContainsString("selectedStatus: @js((string) (\$status ?? ''))", $source);
        $this->assertStringContainsString('name="status" x-model="selectedStatus"', $source);
        $this->assertStringContainsString(
            'class="relative w-full px-3 py-2 pr-16 border border-gray-200 rounded-lg text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition text-left"',
            $source,
        );
        $this->assertStringContainsString(
            'class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"',
            $source,
        );
        $this->assertStringContainsString('class="border-t border-gray-100"', $source);
        $this->assertStringContainsString('x-for="status in statuses.slice(1)"', $source);
        $this->assertStringContainsString("value: '',", $source);
        $this->assertStringContainsString("label: @js(__('contracts.index.all_statuses'))", $source);
        $this->assertStringContainsString("value: 'active'", $source);
        $this->assertStringContainsString("value: 'terminated'", $source);
        $this->assertStringNotContainsString('x-show="status.value === selectedStatus"', $source);
        $this->assertStringNotContainsString('bg-blue-50 text-blue-700 font-medium', substr($source, strpos($source, '{{-- Фильтр по статусу --}}')));
        $this->assertStringNotContainsString('M5 13l4 4L19 7', substr($source, strpos($source, '{{-- Фильтр по статусу --}}')));
        $this->assertStringNotContainsString('<select name="status"', $source);
    }
}
