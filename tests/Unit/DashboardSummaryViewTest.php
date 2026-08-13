<?php

namespace Tests\Unit;

use Tests\TestCase;

class DashboardSummaryViewTest extends TestCase
{
    public function test_dashboard_uses_one_financial_summary_with_secondary_counters(): void
    {
        $source = file_get_contents(resource_path('views/dashboard.blade.php'));

        $this->assertStringContainsString('data-testid="dashboard-financial-summary"', $source);
        $this->assertStringContainsString('data-testid="dashboard-secondary-counters"', $source);

        foreach ([
            'Общий долг',
            'Выставлено',
            'Оплачено',
            'Просрочено',
            'Активные компании',
            'Подписки',
        ] as $label) {
            $this->assertStringContainsString($label, $source);
        }

        $this->assertStringNotContainsString(
            'mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4',
            $source
        );
        $this->assertStringContainsString('<table class="crm-table">', $source);
        $this->assertStringContainsString('class="crm-table-shell"', $source);
    }
}
