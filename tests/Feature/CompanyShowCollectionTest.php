<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CompanyShowCollectionTest extends CompanyFinancialTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
        ]);
    }

    public function test_company_invoice_collection_includes_drafts_without_affecting_financial_totals(): void
    {
        $company = $this->company('Draft Invoice Company');
        $draft = $this->invoice($company, 'INV-DRAFT-COMPANY', 'draft', '100.00');
        $issued = $this->invoice($company, 'INV-ISSUED-COMPANY', 'issued', '250.00');
        $otherCompanyDraft = $this->invoice($this->company('Other Invoice Company'), 'INV-OTHER-DRAFT', 'draft', '999.00');

        $response = $this->get(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee($draft->invoice_number)
            ->assertSee($issued->invoice_number)
            ->assertSee('Черновик')
            ->assertDontSee($otherCompanyDraft->invoice_number);

        $this->assertSame(250.0, $response->viewData('stats')['total_invoiced']);
        $this->assertStringContainsString('class="crm-badge crm-badge-neutral"', $response->getContent());
    }

    public function test_company_collection_tables_share_the_internal_scroll_pattern(): void
    {
        $view = file_get_contents(resource_path('views/companies/show.blade.php'));
        $tableStyles = file_get_contents(resource_path('views/components/tables/styles.blade.php'));

        $this->assertSame(4, substr_count($view, 'class="crm-table-collection-scroll"'));
        $this->assertStringContainsString('.crm-table-collection-scroll {', $tableStyles);
        $this->assertStringContainsString('max-height: 380px;', $tableStyles);
        $this->assertStringContainsString('overflow-x: auto;', $tableStyles);
        $this->assertStringContainsString('overflow-y: auto;', $tableStyles);
        $this->assertStringContainsString('padding-right: 0.75rem;', $tableStyles);
        $this->assertStringContainsString('scrollbar-gutter: stable;', $tableStyles);
        $this->assertStringContainsString('.crm-table-collection-scroll .crm-table thead {', $tableStyles);
        $this->assertStringContainsString('position: sticky;', $tableStyles);
        $this->assertStringContainsString('top: 0;', $tableStyles);
        $this->assertStringContainsString('background: #f8fafc;', $tableStyles);
    }

    public function test_company_invoice_order_matches_invoice_index_default_and_keeps_new_draft_at_top(): void
    {
        $company = $this->company('Invoice Order Company');
        $this->invoice($company, 'INV-OLDER', 'issued', '100.00', '2026-08-01');
        $freshDraft = $this->invoice($company, 'INV-FRESH-DRAFT', 'draft', '200.00', '2026-08-24');
        $this->invoice($company, 'INV-OTHER-COMPANY', 'issued', '300.00', '2026-08-15');
        $otherCompany = $this->company('Other Order Company');
        $this->invoice($otherCompany, 'INV-NOT-LEAKED', 'draft', '900.00', '2026-08-26');

        $indexHtml = $this->get(route('invoices.index', ['company_id' => $company->id]))
            ->assertOk()
            ->getContent();
        $companyHtml = $this->get(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee($freshDraft->invoice_number)
            ->assertDontSee('INV-NOT-LEAKED')
            ->getContent();

        $this->assertInvoiceOrder($indexHtml, ['INV-FRESH-DRAFT', 'INV-OTHER-COMPANY', 'INV-OLDER']);
        $this->assertInvoiceOrder($companyHtml, ['INV-FRESH-DRAFT', 'INV-OTHER-COMPANY', 'INV-OLDER']);
    }

    private function company(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function invoice(
        Company $company,
        string $number,
        string $status,
        string $amount,
        string $issueDate = '2026-08-01',
    ): Invoice {
        return Invoice::query()->create([
            'company_id' => $company->id,
            'invoice_number' => $number,
            'issue_date' => $issueDate,
            'due_date' => '2026-08-31',
            'total_amount' => $amount,
            'status' => $status,
        ]);
    }

    /** @param list<string> $invoiceNumbers */
    private function assertInvoiceOrder(string $html, array $invoiceNumbers): void
    {
        $positions = array_map(
            fn (string $invoiceNumber): int => strpos($html, $invoiceNumber),
            $invoiceNumbers,
        );

        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, [...$positions]);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }
}
