<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FinancialTestCase as TestCase;
use Tests\Support\DomainQueryRecorder;

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

    public function test_filters_by_contract_id(): void
    {
        $match = $this->invoice(['invoice_number' => 'INV-CONTRACT-MATCH']);
        $other = $this->invoice(['invoice_number' => 'INV-CONTRACT-OTHER']);

        $this->get(route('invoices.index', ['contract_id' => $match->contract_id]))
            ->assertOk()
            ->assertSee($match->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_invalid_company_and_contract_filters_are_removed_from_pagination_state(): void
    {
        $this->invoice(['invoice_number' => 'INV-NORMALIZED']);

        $response = $this->get(route('invoices.index', [
            'company_id' => 'abc',
            'contract_id' => '999999',
            'status' => 'garbage',
            'sort' => 'drop_table',
            'direction' => 'sideways',
            'overdue' => 'garbage',
        ]))->assertOk();

        $response->assertSee('INV-NORMALIZED');
        $invoices = $response->viewData('invoices');
        $nextPage = $invoices->url(2);

        $this->assertStringNotContainsString('company_id', $nextPage);
        $this->assertStringNotContainsString('contract_id', $nextPage);
        $this->assertStringNotContainsString('status=', $nextPage);
        $this->assertStringNotContainsString('drop_table', $nextPage);
        $this->assertStringNotContainsString('sideways', $nextPage);
        $this->assertStringNotContainsString('overdue=', $nextPage);
    }

    public function test_company_and_contract_filters_compose_literally(): void
    {
        $match = $this->invoice([], 'Filter Company');
        $otherCompany = $this->invoice([], 'Other Filter Company');

        $this->get(route('invoices.index', [
            'company_id' => $match->company_id,
            'contract_id' => $otherCompany->contract_id,
        ]))->assertOk()
            ->assertDontSee($match->invoice_number)
            ->assertDontSee($otherCompany->invoice_number)
            ->assertSee('Счетов не найдено.');
    }

    public function test_equal_dates_use_id_tie_break_for_pagination(): void
    {
        $invoices = [];
        for ($index = 1; $index <= 21; $index++) {
            $invoices[] = $this->invoice([
                'invoice_number' => sprintf('INV-TIE-%02d', $index),
                'issue_date' => '2026-03-01',
            ]);
        }

        $pageOne = $this->get(route('invoices.index', [
            'sort' => 'issue_date',
            'direction' => 'desc',
        ]))->assertOk()->viewData('invoices')->getCollection()->pluck('id');
        $pageTwo = $this->get(route('invoices.index', [
            'sort' => 'issue_date',
            'direction' => 'desc',
            'page' => 2,
        ]))->assertOk()->viewData('invoices')->getCollection()->pluck('id');

        $this->assertCount(10, $pageOne);
        $this->assertCount(10, $pageTwo);
        $this->assertSame([], array_values(array_intersect($pageOne->all(), $pageTwo->all())));
        $this->assertSame($invoices[20]->id, $pageOne->first());
        $this->assertSame($invoices[10]->id, $pageTwo->first());
    }

    public function test_index_query_count_is_bounded_as_invoice_rows_grow(): void
    {
        $this->invoice(['invoice_number' => 'INV-BOUND-ONE']);
        $one = (new DomainQueryRecorder)->capture(
            fn () => $this->get(route('invoices.index'))->assertOk(),
        );

        for ($index = 2; $index <= 10; $index++) {
            $this->invoice(['invoice_number' => 'INV-BOUND-'.$index]);
        }

        $ten = (new DomainQueryRecorder)->capture(
            fn () => $this->get(route('invoices.index'))->assertOk(),
        );

        $this->assertSame(
            DomainQueryRecorder::count($one['records']),
            DomainQueryRecorder::count($ten['records']),
        );
    }

    public function test_index_renders_line_derived_periods_without_false_gap_range(): void
    {
        $continuous = $this->invoice(['invoice_number' => 'INV-PERIOD-CONTINUOUS']);
        InvoiceLine::create(['invoice_id' => $continuous->id, 'description' => 'June', 'amount' => 50, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
        InvoiceLine::create(['invoice_id' => $continuous->id, 'description' => 'July', 'amount' => 50, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31']);

        $disjoint = $this->invoice(['invoice_number' => 'INV-PERIOD-DISJOINT']);
        InvoiceLine::create(['invoice_id' => $disjoint->id, 'description' => 'June', 'amount' => 50, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
        InvoiceLine::create(['invoice_id' => $disjoint->id, 'description' => 'August', 'amount' => 50, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);

        $this->get(route('invoices.index'))->assertOk()
            ->assertSee('Расчётный период')
            ->assertSee('Выставлен / срок')
            ->assertSee('01/06/2026 — 31/07/2026')
            ->assertSee('2 расчётных периода')
            ->assertSee('Несколько расчётных периодов')
            ->assertDontSee('01/06/2026 — 31/08/2026');
    }

    public function test_status_filters_accept_all_lifecycle_statuses_and_multiple_values(): void
    {
        $statuses = ['draft', 'issued', 'partially_paid', 'paid', 'cancelled'];

        foreach ($statuses as $status) {
            $this->invoice(['status' => $status, 'invoice_number' => 'INV-'.$status]);
        }

        $this->get(route('invoices.index'))->assertOk()
            ->assertSee('INV-draft')
            ->assertSee('INV-issued')
            ->assertSee('INV-partially_paid')
            ->assertSee('INV-paid')
            ->assertSee('INV-cancelled');

        foreach ($statuses as $status) {
            $response = $this->get(route('invoices.index', ['statuses' => [$status]]))->assertOk();
            $response->assertSee('INV-'.$status);

            foreach (array_diff($statuses, [$status]) as $otherStatus) {
                $response->assertDontSee('INV-'.$otherStatus);
            }
        }

        $this->get(route('invoices.index', ['statuses' => ['paid', 'cancelled']]))->assertOk()
            ->assertSee('INV-paid')
            ->assertSee('INV-cancelled')
            ->assertDontSee('INV-draft');
    }

    public function test_invalid_status_is_ignored(): void
    {
        $invoice = $this->invoice(['status' => 'issued']);

        $this->get(route('invoices.index', ['statuses' => ['not-a-status']]))
            ->assertOk()
            ->assertSee($invoice->invoice_number);
    }

    public function test_legacy_status_is_compatible_but_canonical_statuses_take_precedence(): void
    {
        $issued = $this->invoice(['status' => 'issued', 'invoice_number' => 'INV-ISSUED']);
        $draft = $this->invoice(['status' => 'draft', 'invoice_number' => 'INV-DRAFT']);

        $response = $this->get(route('invoices.index', ['status' => 'issued']))->assertOk();

        $response->assertSee($issued->invoice_number)
            ->assertDontSee($draft->invoice_number);

        $this->get(route('invoices.index', ['status' => 'draft', 'statuses' => ['issued']]))->assertOk()
            ->assertSee($issued->invoice_number)
            ->assertDontSee($draft->invoice_number);
    }

    public function test_unpaid_filters_to_issued_and_partially_paid_and_intersects_statuses(): void
    {
        foreach (['draft', 'issued', 'partially_paid', 'paid', 'cancelled'] as $status) {
            $this->invoice(['status' => $status, 'invoice_number' => 'INV-'.$status]);
        }

        $this->get(route('invoices.index', ['unpaid' => 1]))->assertOk()
            ->assertSee('INV-issued')
            ->assertSee('INV-partially_paid')
            ->assertDontSee('INV-draft')
            ->assertDontSee('INV-paid')
            ->assertDontSee('INV-cancelled');

        $this->get(route('invoices.index', ['unpaid' => 1, 'statuses' => ['issued']]))->assertOk()
            ->assertSee('INV-issued')
            ->assertDontSee('INV-partially_paid');

        $this->get(route('invoices.index', ['unpaid' => 1, 'statuses' => ['paid', 'partially_paid']]))->assertOk()
            ->assertSee('INV-partially_paid')
            ->assertDontSee('INV-paid');
    }

    public function test_unpaid_and_overdue_combine_without_leaking_paid_invoices(): void
    {
        $this->invoice(['status' => 'issued', 'due_date' => now()->subDay()->toDateString(), 'invoice_number' => 'INV-OVERDUE-ISSUED']);
        $this->invoice(['status' => 'partially_paid', 'due_date' => now()->subDay()->toDateString(), 'invoice_number' => 'INV-OVERDUE-PARTIAL']);
        $this->invoice(['status' => 'paid', 'due_date' => now()->subDay()->toDateString(), 'invoice_number' => 'INV-OVERDUE-PAID']);
        $this->invoice(['status' => 'issued', 'due_date' => now()->addDay()->toDateString(), 'invoice_number' => 'INV-CURRENT-ISSUED']);

        $this->get(route('invoices.index', ['unpaid' => 1, 'overdue' => 1, 'statuses' => ['partially_paid']]))->assertOk()
            ->assertSee('INV-OVERDUE-PARTIAL')
            ->assertDontSee('INV-OVERDUE-ISSUED')
            ->assertDontSee('INV-OVERDUE-PAID')
            ->assertDontSee('INV-CURRENT-ISSUED');
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
        $this->invoice(['company_id' => $companyId, 'status' => 'paid', 'payer_name' => 'Shared Payer', 'issue_date' => '2026-01-01', 'invoice_number' => 'INV-FIRST']);
        $this->invoice(['company_id' => $companyId, 'status' => 'paid', 'payer_name' => 'Shared Payer', 'issue_date' => '2026-02-01', 'invoice_number' => 'INV-SECOND']);
        $this->invoice(['company_id' => $companyId, 'status' => 'draft', 'payer_name' => 'Shared Payer', 'invoice_number' => 'INV-WRONG-STATUS']);
        $this->invoice(['status' => 'paid', 'payer_name' => 'Shared Payer', 'invoice_number' => 'INV-WRONG-COMPANY']);

        $this->get(route('invoices.index', [
            'search' => 'Shared Payer',
            'company_id' => $companyId,
            'statuses' => ['paid'],
            'sort' => 'issue_date',
            'direction' => 'asc',
        ]))->assertOk()
            ->assertSeeInOrder(['INV-FIRST', 'INV-SECOND'])
            ->assertDontSee('INV-WRONG-STATUS')
            ->assertDontSee('INV-WRONG-COMPANY');
    }

    public function test_statuses_and_unpaid_are_preserved_in_pagination_and_sorting(): void
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
            'statuses' => ['issued', 'partially_paid'],
            'overdue' => 1,
            'unpaid' => 1,
            'sort' => 'due_date',
            'direction' => 'asc',
            'page' => 2,
        ]);

        $response = $this->get(route('invoices.index', [
            'search' => 'Pagination Match',
            'company_id' => $companyId,
            'statuses' => ['issued', 'partially_paid'],
            'unpaid' => 1,
            'overdue' => 1,
            'sort' => 'due_date',
            'direction' => 'asc',
        ]))->assertOk()->assertSee($url);

        $response->assertSee(route('invoices.index', [
            'search' => 'Pagination Match',
            'company_id' => $companyId,
            'statuses' => ['issued', 'partially_paid'],
            'overdue' => 1,
            'unpaid' => 1,
            'sort' => 'issue_date',
            'direction' => 'desc',
        ]));
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
