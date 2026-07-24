<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthenticatedTestCase as TestCase;

class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_has_updated_heading_and_search_form(): void
    {
        $response = $this->get(route('companies.index'))->assertOk();

        $response->assertSee('Управление клиентами и реквизитами')
            ->assertDontSee('Управление клиентами, контрагентами и реквизитами')
            ->assertSee('action="'.route('companies.index').'"', false)
            ->assertSee('Поиск по названию, краткому имени или VÖEN…');
    }

    public function test_company_name_and_open_action_link_to_show_without_filter_context(): void
    {
        $company = $this->company('Linked Company');
        $showUrl = route('companies.show', $company);

        $response = $this->get(route('companies.index', [
            'search' => 'Linked',
            'status' => 'active',
        ]))->assertOk();

        $response->assertSee('href="'.$showUrl.'"', false)
            ->assertSee('Linked Company')
            ->assertSee('Открыть →')
            ->assertDontSee('href="'.route('companies.edit', $company).'">Linked Company', false);
    }

    public function test_search_matches_name_short_name_and_voen_partially(): void
    {
        $this->company('SkyCell Holdings', 'SkyCell', '1234567890');
        $this->company('Unrelated Company', 'Other', '9999999999');

        foreach (['Cell Hold', 'SkyC', '45678'] as $search) {
            $response = $this->get(route('companies.index', ['search' => $search]))->assertOk();
            $names = $response->viewData('companies')->getCollection()->pluck('name')->all();
            $this->assertSame(['SkyCell Holdings'], $names);
        }
    }

    public function test_search_and_status_filter_work_together(): void
    {
        $this->company('Matching Active', status: 'active');
        $this->company('Matching Suspended', status: 'suspended');
        $this->company('Matching Archived', status: 'archived');

        $activeNames = $this->get(route('companies.index', ['search' => 'Matching', 'status' => 'active']))
            ->viewData('companies')->getCollection()->pluck('name')->all();
        $suspendedNames = $this->get(route('companies.index', ['search' => 'Matching', 'status' => 'suspended']))
            ->viewData('companies')->getCollection()->pluck('name')->all();
        $archivedNames = $this->get(route('companies.index', ['search' => 'Matching', 'status' => 'archived']))
            ->viewData('companies')->getCollection()->pluck('name')->all();

        $this->assertSame(['Matching Active'], $activeNames);
        $this->assertSame(['Matching Suspended'], $suspendedNames);
        $this->assertSame(['Matching Archived'], $archivedNames);
    }

    public function test_status_filter_and_labels_use_company_wording(): void
    {
        $this->company('Active Company', status: 'active');
        $this->company('Suspended Company', status: 'suspended');
        $this->company('Archived Company', status: 'archived');

        $this->get(route('companies.index'))
            ->assertSee('Active Company')->assertSee('Suspended Company')->assertSee('Archived Company')
            ->assertSee('Активна')->assertSee('Приостановлена')->assertSee('В архиве');
        $this->get(route('companies.index', ['status' => 'active']))
            ->assertSee('Active Company')->assertDontSee('Suspended Company')->assertDontSee('Archived Company');
        $this->get(route('companies.index', ['status' => 'suspended']))
            ->assertDontSee('Active Company')->assertSee('Suspended Company')->assertDontSee('Archived Company');
        $this->get(route('companies.index', ['status' => 'archived']))
            ->assertDontSee('Active Company')->assertDontSee('Suspended Company')->assertSee('Archived Company');

        $response = $this->get(route('companies.index', ['status' => 'unexpected']))->assertOk();
        $this->assertSame('', $response->viewData('status'));
        $this->assertCount(3, $response->viewData('companies')->getCollection());
    }

    public function test_autocomplete_requires_two_characters_searches_all_fields_and_limits_output(): void
    {
        $this->company('SkyCell Holdings', 'SkyCell', '1234567890');
        foreach (range(1, 12) as $number) {
            $this->company('Matching '.$number, 'Short '.$number, '900'.$number);
        }

        $this->getJson(route('companies.autocomplete', ['q' => 'S']))
            ->assertOk()->assertExactJson([]);

        foreach (['SkyCell', 'SkyC', '45678'] as $query) {
            $this->getJson(route('companies.autocomplete', ['q' => $query]))
                ->assertOk()
                ->assertJsonFragment(['name' => 'SkyCell Holdings']);
        }

        $results = $this->getJson(route('companies.autocomplete', ['q' => 'Matching']))
            ->assertOk()->json();
        $this->assertCount(10, $results);
        $this->assertSame(['id', 'name', 'type_label', 'voen'], array_keys($results[0]));
        $this->assertArrayNotHasKey('email', $results[0]);
        $this->assertArrayNotHasKey('bank_name', $results[0]);
    }

    public function test_name_sorting_is_stable_in_both_directions_and_invalid_values_are_safe(): void
    {
        $this->company('Beta');
        $this->company('Alpha');
        $this->company('Alpha');

        $asc = $this->indexNames(['sort' => 'name', 'direction' => 'asc']);
        $desc = $this->indexNames(['sort' => 'name', 'direction' => 'desc']);
        $invalid = $this->get(route('companies.index', ['sort' => 'drop table', 'direction' => 'sideways']))
            ->assertOk();

        $this->assertSame(['Alpha', 'Alpha', 'Beta'], $asc);
        $this->assertSame(['Beta', 'Alpha', 'Alpha'], $desc);
        $this->assertSame('name', $invalid->viewData('sort'));
        $this->assertSame('asc', $invalid->viewData('direction'));
    }

    public function test_debt_sorting_uses_the_existing_invoice_and_confirmed_payment_rules(): void
    {
        $zeroDebt = $this->company('Zero Debt');
        $smallDebt = $this->company('Small Debt');
        $largeDebt = $this->company('Large Debt');
        $this->invoice($zeroDebt, 'draft', 999);
        $smallInvoice = $this->invoice($smallDebt, 'partially_paid', 100);
        $this->payment($smallInvoice, 'confirmed', 75);
        $this->payment($smallInvoice, 'pending', 20);
        $this->invoice($largeDebt, 'issued', 200);

        $this->assertSame(
            ['Zero Debt', 'Small Debt', 'Large Debt'],
            $this->indexNames(['sort' => 'debt', 'direction' => 'asc'])
        );
        $this->assertSame(
            ['Large Debt', 'Small Debt', 'Zero Debt'],
            $this->indexNames(['sort' => 'debt', 'direction' => 'desc'])
        );
    }

    public function test_sort_links_and_pagination_preserve_filters_without_page(): void
    {
        foreach (['active', 'suspended', 'archived'] as $status) {
            foreach (range(1, 12) as $number) {
                $this->company(ucfirst($status).' Search Company '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), status: $status);
            }

            $response = $this->get(route('companies.index', [
                'search' => 'Search',
                'status' => $status,
                'sort' => 'debt',
                'direction' => 'desc',
            ]))->assertOk();

            $response->assertSee('search=Search', false)
                ->assertSee('status='.$status, false)
                ->assertSee('sort=debt', false)
                ->assertSee('direction=desc', false)
                ->assertSee('page=2', false);
        }
    }

    public function test_edit_from_index_preserves_only_whitelisted_list_context(): void
    {
        $company = $this->company('Context Company');
        foreach (['suspended', 'archived'] as $status) {
            $context = [
                'origin' => 'index',
                'search' => 'Context',
                'status' => $status,
                'sort' => 'debt',
                'direction' => 'desc',
                'page' => 2,
                'return_url' => 'https://evil.example',
            ];
            $expected = route('companies.index', array_diff_key($context, ['origin' => true, 'return_url' => true]));

            $response = $this->get(route('companies.edit', ['company' => $company, ...$context]))->assertOk();
            $response->assertSee($expected)
                ->assertSee('name="origin" value="index"', false)
                ->assertSee('name="status" value="'.$status.'"', false)
                ->assertDontSee('evil.example');

            $this->put(route('companies.update', $company), [
                ...$this->updatePayload($company),
                ...$context,
            ])->assertRedirect($expected);
        }
    }

    public function test_edit_from_show_and_invalid_origin_return_to_company_show(): void
    {
        $company = $this->company('Show Context');
        $showUrl = route('companies.show', $company);

        $this->get(route('companies.edit', ['company' => $company, 'origin' => 'show']))
            ->assertOk()->assertSee($showUrl, false);
        $this->put(route('companies.update', $company), [
            ...$this->updatePayload($company),
            'origin' => 'show',
        ])->assertRedirect($showUrl);
        $this->put(route('companies.update', $company), [
            ...$this->updatePayload($company),
            'origin' => 'https://evil.example',
            'return_url' => 'https://evil.example',
        ])->assertRedirect($showUrl);
    }

    public function test_validation_error_keeps_index_context_in_the_edit_url(): void
    {
        $company = $this->company('Validation Context');
        $editUrl = route('companies.edit', [
            'company' => $company,
            'origin' => 'index',
            'search' => 'Validation',
            'status' => 'active',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => 2,
        ]);

        $this->from($editUrl)->put(route('companies.update', $company), [
            ...$this->updatePayload($company),
            'name' => '',
            'origin' => 'index',
            'search' => 'Validation',
            'status' => 'active',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => 2,
        ])->assertRedirect($editUrl)->assertSessionHasErrors('name');
    }

    private function indexNames(array $query): array
    {
        return $this->get(route('companies.index', $query))
            ->assertOk()
            ->viewData('companies')
            ->getCollection()
            ->pluck('name')
            ->all();
    }

    private function company(
        string $name,
        ?string $shortName = null,
        ?string $voen = null,
        string $status = 'active'
    ): Company {
        return Company::create([
            'type' => 'company',
            'name' => $name,
            'short_name' => $shortName,
            'voen' => $voen,
            'status' => $status,
            'invoice_mode' => 'separate',
        ]);
    }

    private function invoice(Company $company, string $status, float $total): Invoice
    {
        return Invoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
    }

    private function payment(Invoice $invoice, string $status, float $amount): Payment
    {
        return Payment::withoutEvents(fn() => Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-21',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]));
    }

    private function updatePayload(Company $company): array
    {
        return [
            'type' => $company->type,
            'name' => $company->name,
            'status' => $company->status,
            'invoice_mode' => $company->invoice_mode,
        ];
    }
}
