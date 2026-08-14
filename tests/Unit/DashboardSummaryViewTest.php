<?php

namespace Tests\Unit;

use Tests\TestCase;

class DashboardSummaryViewTest extends TestCase
{
    public function test_dashboard_permission_fallback_uses_the_compact_neutral_section(): void
    {
        $source = file_get_contents(resource_path('views/dashboard.blade.php'));

        $this->assertStringContainsString('data-testid="dashboard-neutral-fallback"', $source);
        $this->assertStringContainsString('>Доступ</h2>', $source);
        $this->assertStringContainsString('Для просмотра показателей у вас нет необходимых прав', $source);
        $this->assertStringNotContainsString(
            'rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm',
            $source,
        );
    }

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
