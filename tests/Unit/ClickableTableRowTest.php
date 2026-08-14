<?php

namespace Tests\Unit;

use Tests\TestCase;

class ClickableTableRowTest extends TestCase
{
    public function test_clickable_row_component_navigates_only_when_the_target_is_not_interactive(): void
    {
        $source = file_get_contents(resource_path('views/components/tables/clickable-row.blade.php'));

        $this->assertStringContainsString('data-row-url="{{ $url }}"', $source);
        $this->assertStringContainsString('tabindex="0"', $source);
        $this->assertStringContainsString('x-on:keydown.enter="navigate($event)"', $source);
        $this->assertStringContainsString('x-on:keydown.space="navigate($event)"', $source);
        $this->assertStringContainsString('a,button,input,select,textarea,label,summary', $source);
        $this->assertStringContainsString('[data-row-click-ignore]', $source);
    }

    public function test_dashboard_company_and_index_rows_use_the_shared_clickable_row_without_open_actions(): void
    {
        $dashboard = file_get_contents(resource_path('views/dashboard.blade.php'));
        $companies = file_get_contents(resource_path('views/companies/index.blade.php'));

        $this->assertStringContainsString('<x-tables.clickable-row', $dashboard);
        $this->assertStringNotContainsString('Открыть →', $dashboard);
        $this->assertStringContainsString("route('companies.show', \$company['model'])", $dashboard);

        $this->assertStringContainsString('<x-tables.clickable-row', $companies);
        $this->assertStringNotContainsString('class="crm-table-action-link">Открыть →', $companies);
        $this->assertStringContainsString("route('companies.edit'", $companies);
        $this->assertStringContainsString("route('companies.show', \$company)", $companies);
    }

    public function test_contract_and_invoice_indexes_use_clickable_rows_without_open_actions(): void
    {
        $contracts = file_get_contents(resource_path('views/contracts/index.blade.php'));
        $invoices = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('<x-tables.clickable-row', $contracts);
        $this->assertStringNotContainsString('Открыть →', $contracts);
        $this->assertStringContainsString("route('contracts.edit'", $contracts);
        $this->assertStringContainsString("route('companies.show'", $contracts);

        $this->assertStringContainsString('<x-tables.clickable-row', $invoices);
        $this->assertStringNotContainsString('aria-label="Открыть счёт', $invoices);
        $this->assertStringContainsString("route('invoices.show', \$invoice)", $invoices);
        $this->assertStringContainsString("route('companies.show'", $invoices);
        $this->assertStringNotContainsString("route('invoices.edit'", $invoices);
    }
}
