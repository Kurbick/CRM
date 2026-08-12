<?php

namespace Tests\Feature\Tables;

use Tests\TestCase;

class UnifiedListUiTest extends TestCase
{
    public function test_shared_table_primitives_cover_density_sorting_alignment_and_states(): void
    {
        $styles = file_get_contents(resource_path('views/components/tables/styles.blade.php'));

        $this->assertIsString($styles);

        foreach ([
            '.crm-table-shell',
            '.crm-table-heading',
            '.crm-table th',
            '.crm-table td',
            '.crm-table-numeric',
            '.crm-table-date',
            '.crm-table-sort-indicator-active',
            '.crm-table-empty',
            '.crm-table-footer',
            '.crm-light-action',
            '.crm-badge-neutral',
            '.crm-badge-success',
            '.crm-badge-warning',
            '.crm-badge-danger',
        ] as $primitive) {
            $this->assertStringContainsString($primitive, $styles);
        }
    }

    public function test_representative_lists_use_the_shared_table_contract(): void
    {
        foreach ([
            'companies' => 'views/companies/index.blade.php',
            'contracts' => 'views/contracts/index.blade.php',
            'invoices' => 'views/invoices/index.blade.php',
            'admin/users' => 'views/admin/users/index.blade.php',
            'dashboard' => 'views/dashboard.blade.php',
        ] as $section => $viewPath) {
            $view = file_get_contents(resource_path($viewPath));

            $this->assertIsString($view);
            $this->assertStringContainsString('crm-table-shell', $view);
            $this->assertStringContainsString('crm-table-heading', $view);
            $this->assertStringContainsString('class="crm-table"', $view);
            if ($section !== 'dashboard') {
                $this->assertStringContainsString('crm-table-sort-indicator', $view);
            }
            $this->assertStringContainsString('crm-table-empty', $view);
            if ($section !== 'dashboard') {
                $this->assertStringContainsString('crm-table-footer', $view);
            }
        }

        foreach ([
            'resources/views/partials/badge.blade.php',
            'resources/views/components/admin/users/role-badge.blade.php',
            'resources/views/components/admin/users/status-badge.blade.php',
        ] as $badge) {
            $source = file_get_contents(base_path($badge));

            $this->assertIsString($source);
            $this->assertStringContainsString('crm-badge', $source);
        }

        foreach ([
            'views/companies/index.blade.php',
            'views/contracts/index.blade.php',
            'views/invoices/index.blade.php',
            'views/admin/users/index.blade.php',
            'views/dashboard.blade.php',
            'views/companies/show.blade.php',
            'views/contracts/show.blade.php',
        ] as $viewPath) {
            $view = file_get_contents(resource_path($viewPath));

            $this->assertIsString($view);
            $this->assertStringContainsString('crm-light-action', $view);
        }
    }
}
