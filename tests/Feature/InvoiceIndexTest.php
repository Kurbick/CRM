<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_searches_by_invoice_number(): void
    {
        $match = $this->invoice(['invoice_number' => 'INV-FIND-ME']);
        $this->invoice(['invoice_number' => 'INV-OTHER']);

        $this->get(route('invoices.index', ['search' => 'FIND-ME']))
            ->assertOk()
            ->assertSee($match->invoice_number)
            ->assertDontSee('INV-OTHER');
    }

    public function test_searches_by_company_name(): void
    {
        $match = $this->invoice([], 'Unique Search Company');
        $this->invoice(['invoice_number' => 'INV-OTHER-COMPANY'], 'Different Company');

        $this->get(route('invoices.index', ['search' => 'Unique Search']))
            ->assertOk()
            ->assertSee($match->invoice_number)
            ->assertDontSee('INV-OTHER-COMPANY');
    }

    public function test_filters_by_existing_company_id(): void
    {
        $match = $this->invoice([], 'Selected Company');
        $other = $this->invoice([], 'Other Company');

        $this->get(route('invoices.index', ['company_id' => $match->company_id]))
            ->assertOk()
            ->assertSee($match->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_each_allowed_status_is_applied(): void
    {
        $statuses = ['draft', 'issued', 'partially_paid', 'paid', 'cancelled'];

        foreach ($statuses as $status) {
            $this->invoice(['status' => $status, 'invoice_number' => 'INV-'.$status]);
        }

        foreach ($statuses as $status) {
            $response = $this->get(route('invoices.index', ['status' => $status]))->assertOk();
            $response->assertSee('INV-'.$status);

            foreach (array_diff($statuses, [$status]) as $otherStatus) {
                $response->assertDontSee('INV-'.$otherStatus);
            }
        }
    }

    public function test_invalid_status_is_ignored(): void
    {
        $invoice = $this->invoice(['status' => 'issued']);

        $this->get(route('invoices.index', ['status' => 'not-a-status']))
            ->assertOk()
            ->assertSee($invoice->invoice_number);
    }

    public function test_sorts_issue_date_descending(): void
    {
        $this->assertInvoiceOrder(['sort' => 'issue_date', 'direction' => 'desc'], 'INV-NEW', 'INV-OLD');
    }

    public function test_sorts_issue_date_ascending(): void
    {
        $this->assertInvoiceOrder(['sort' => 'issue_date', 'direction' => 'asc'], 'INV-OLD', 'INV-NEW');
    }

    public function test_sorts_due_date_descending(): void
    {
        $this->assertInvoiceOrder(['sort' => 'due_date', 'direction' => 'desc'], 'INV-NEW', 'INV-OLD', 'due_date');
    }

    public function test_sorts_due_date_ascending(): void
    {
        $this->assertInvoiceOrder(['sort' => 'due_date', 'direction' => 'asc'], 'INV-OLD', 'INV-NEW', 'due_date');
    }

    public function test_arbitrary_sort_column_falls_back_to_issue_date(): void
    {
        $this->assertInvoiceOrder(['sort' => 'invoice_number desc; drop table invoices', 'direction' => 'asc'], 'INV-OLD', 'INV-NEW');
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_arbitrary_direction_falls_back_to_descending(): void
    {
        $this->assertInvoiceOrder(['sort' => 'issue_date', 'direction' => 'sideways'], 'INV-NEW', 'INV-OLD');
    }

    public function test_search_company_status_and_sort_work_together(): void
    {
        $companyId = $this->company('Combined Company');
        $this->invoice(['company_id' => $companyId, 'status' => 'issued', 'payer_name' => 'Shared Payer', 'issue_date' => '2026-01-01', 'invoice_number' => 'INV-FIRST']);
        $this->invoice(['company_id' => $companyId, 'status' => 'issued', 'payer_name' => 'Shared Payer', 'issue_date' => '2026-02-01', 'invoice_number' => 'INV-SECOND']);
        $this->invoice(['company_id' => $companyId, 'status' => 'paid', 'payer_name' => 'Shared Payer', 'invoice_number' => 'INV-WRONG-STATUS']);
        $this->invoice(['status' => 'issued', 'payer_name' => 'Shared Payer', 'invoice_number' => 'INV-WRONG-COMPANY']);

        $this->get(route('invoices.index', [
            'search' => 'Shared Payer',
            'company_id' => $companyId,
            'status' => 'issued',
            'sort' => 'issue_date',
            'direction' => 'asc',
        ]))->assertOk()
            ->assertSeeInOrder(['INV-FIRST', 'INV-SECOND'])
            ->assertDontSee('INV-WRONG-STATUS')
            ->assertDontSee('INV-WRONG-COMPANY');
    }

    public function test_query_parameters_are_preserved_in_pagination(): void
    {
        $companyId = $this->company('Paginated Company');

        for ($index = 1; $index <= 11; $index++) {
            $this->invoice([
                'company_id' => $companyId,
                'status' => 'issued',
                'payer_name' => 'Pagination Match',
                'invoice_number' => sprintf('PAGE-%02d', $index),
            ]);
        }

        $url = route('invoices.index', [
            'search' => 'Pagination Match',
            'company_id' => $companyId,
            'status' => 'issued',
            'sort' => 'due_date',
            'direction' => 'asc',
            'page' => 2,
        ]);

        $this->get(route('invoices.index', [
            'search' => 'Pagination Match',
            'company_id' => $companyId,
            'status' => 'issued',
            'sort' => 'due_date',
            'direction' => 'asc',
        ]))->assertOk()->assertSee($url);
    }

    public function test_search_form_submits_to_invoice_index(): void
    {
        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('action="'.route('invoices.index').'"', false)
            ->assertDontSee('action="'.route('contracts.index').'"', false);
    }

    private function assertInvoiceOrder(array $query, string $first, string $second, string $column = 'issue_date'): void
    {
        $this->invoice(['invoice_number' => 'INV-OLD', $column => '2026-01-01']);
        $this->invoice(['invoice_number' => 'INV-NEW', $column => '2026-02-01']);

        $this->get(route('invoices.index', $query))
            ->assertOk()
            ->assertSeeInOrder([$first, $second]);
    }

    private function invoice(array $attributes = [], ?string $companyName = null): Invoice
    {
        $companyId = $attributes['company_id'] ?? $this->company($companyName ?? 'Company '.uniqid());
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.uniqid(),
            'start_date' => '2026-01-01',
        ]);

        return Invoice::create(array_merge([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'total_amount' => 100,
            'status' => 'draft',
        ], $attributes));
    }

    private function company(string $name): int
    {
        return DB::table('companies')->insertGetId(['name' => $name]);
    }
}
