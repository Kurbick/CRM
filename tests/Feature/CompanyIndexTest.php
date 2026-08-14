<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\CompanyTestCase as TestCase;

class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grantCompanyPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::CompaniesCreate->value,
            PermissionName::CompaniesUpdate->value,
        ]);
    }

    public function test_index_has_updated_heading_and_search_form(): void
    {
        $response = $this->get(route('companies.index'))->assertOk();

        $response->assertSee('Управление клиентами и реквизитами')
            ->assertDontSee('Управление клиентами, контрагентами и реквизитами')
            ->assertSee('action="'.route('companies.index').'"', false)
            ->assertSee('Поиск по названию, краткому имени или VÖEN…');
    }

    public function test_company_can_be_created_without_removed_invoice_setting(): void
    {
        $response = $this->post(route('companies.store'), [
            'type' => 'company',
            'name' => 'Created Without Removed Setting',
            'status' => 'active',
        ])->assertRedirect();

        $company = Company::query()->where('name', 'Created Without Removed Setting')->firstOrFail();

        $this->assertSame(route('companies.show', $company), $response->headers->get('Location'));
        $this->assertSame('active', $company->status);
    }

    public function test_company_views_do_not_render_removed_invoice_setting(): void
    {
        $company = $this->company('Company Without Removed Setting');
        $removedField = implode('_', ['invoice', 'mode']);

        $this->get(route('companies.create'))
            ->assertOk()
            ->assertDontSee('name="'.$removedField.'"', false);
        $this->get(route('companies.edit', $company))
            ->assertOk()
            ->assertDontSee('name="'.$removedField.'"', false);
        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee($removedField);
    }

    public function test_company_create_and_edit_share_the_compact_form_workspace(): void
    {
        $company = $this->company('Workspace Company', 'Workspace', '1234567890', 'suspended');

        $create = $this->get(route('companies.create'))->assertOk();

        $create->assertSee('Новая компания')
            ->assertSee('data-testid="company-form-workspace"', false)
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2', false)
            ->assertSeeInOrder(['Активна', 'Приостановлена', 'В архиве'], false)
            ->assertDontSee('>Активен<', false)
            ->assertDontSee('lg:grid-cols-3', false)
            ->assertDontSee('name="invoice_mode"', false);

        foreach ([
            'name', 'short_name', 'type', 'voen', 'email', 'phone', 'website', 'status',
            'legal_address', 'actual_address', 'bank_name', 'iban', 'bank_code', 'bank_voen', 'swift', 'comment',
        ] as $field) {
            $create->assertSee('name="'.$field.'"', false);
        }

        $this->get(route('companies.edit', ['company' => $company, 'origin' => 'show']))
            ->assertOk()
            ->assertSee('Редактирование компании')
            ->assertSee('Назад к компании')
            ->assertSee($company->name)
            ->assertSee('value="'.$company->short_name.'"', false)
            ->assertSee('value="suspended" selected', false)
            ->assertSee('name="origin" value="show"', false)
            ->assertSee('data-testid="company-form-workspace"', false);
    }

    public function test_company_name_link_and_clickable_row_open_the_company_without_removing_edit_action(): void
    {
        $company = $this->company('Linked Company');
        $showUrl = route('companies.show', $company);

        $response = $this->get(route('companies.index', [
            'search' => 'Linked',
            'status' => 'active',
        ]))->assertOk();

        $response->assertSee('href="'.$showUrl.'"', false)
            ->assertSee('Linked Company')
            ->assertSee('data-row-url=', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('title="Редактировать"', false)
            ->assertDontSee('Открыть →')
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

    public function test_forbidden_debt_sort_is_normalized_across_pagination(): void
    {
        $this->authenticatedUser->revokePermissionTo(
            PermissionName::CompaniesFinancialsView->value
        );
        foreach (range(1, 12) as $number) {
            $this->company(
                sprintf('Restricted Pagination %02d', $number),
                status: 'suspended'
            );
        }

        $firstPage = $this->get(route('companies.index', [
            'search' => 'Restricted Pagination',
            'status' => 'suspended',
            'sort' => 'debt',
            'direction' => 'desc',
            'unexpected' => 'SECRET-UNKNOWN-PARAMETER',
        ]))->assertOk()
            ->assertDontSee('company-debt-column')
            ->assertDontSee('sort=debt', false)
            ->assertDontSee('sort%3Ddebt', false)
            ->assertDontSee('SECRET-UNKNOWN-PARAMETER', false);

        $paginator = $firstPage->viewData('companies');
        $nextPageUrl = $paginator->nextPageUrl();
        $returnUrl = $firstPage->viewData('companyIndexReturnUrl');

        $this->assertTrue($paginator->hasMorePages());
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('search=Restricted%20Pagination', $nextPageUrl);
        $this->assertStringContainsString('status=suspended', $nextPageUrl);
        $this->assertStringContainsString('sort=name', $nextPageUrl);
        $this->assertStringContainsString('direction=desc', $nextPageUrl);
        $this->assertStringNotContainsString('sort=debt', $nextPageUrl);
        $this->assertStringNotContainsString('unexpected', $nextPageUrl);
        $this->assertStringNotContainsString('%2520', $returnUrl);

        $secondPage = $this->get($nextPageUrl)->assertOk()
            ->assertSee('Restricted Pagination 02')
            ->assertSee('Restricted Pagination 01')
            ->assertDontSee('Restricted Pagination 03')
            ->assertDontSee('company-debt-column')
            ->assertDontSee('sort=debt', false)
            ->assertDontSee('sort%3Ddebt', false);

        $this->assertSame('Restricted Pagination', $secondPage->viewData('search'));
        $this->assertSame('suspended', $secondPage->viewData('status'));
        $this->assertSame('name', $secondPage->viewData('sort'));
        $this->assertSame('desc', $secondPage->viewData('direction'));
        $this->assertStringNotContainsString(
            '%2520',
            $secondPage->viewData('companyIndexReturnUrl')
        );
    }

    public function test_allowed_debt_sort_is_preserved_across_pagination(): void
    {
        foreach (range(1, 12) as $number) {
            $this->company(
                sprintf('Financial Pagination %02d', $number),
                status: 'archived'
            );
        }

        $firstPage = $this->get(route('companies.index', [
            'search' => 'Financial Pagination',
            'status' => 'archived',
            'sort' => 'debt',
            'direction' => 'desc',
        ]))->assertOk()
            ->assertSee('company-debt-column');

        $paginator = $firstPage->viewData('companies');
        $nextPageUrl = $paginator->nextPageUrl();

        $this->assertTrue($paginator->hasMorePages());
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('search=Financial%20Pagination', $nextPageUrl);
        $this->assertStringContainsString('status=archived', $nextPageUrl);
        $this->assertStringContainsString('sort=debt', $nextPageUrl);
        $this->assertStringContainsString('direction=desc', $nextPageUrl);

        $secondPage = $this->get($nextPageUrl)->assertOk()
            ->assertSee('Financial Pagination 02')
            ->assertSee('Financial Pagination 01')
            ->assertDontSee('Financial Pagination 03')
            ->assertSee('company-debt-column')
            ->assertSee('sort=debt', false);

        $this->assertSame('debt', $secondPage->viewData('sort'));
        $this->assertSame('archived', $secondPage->viewData('status'));
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
        return Payment::withoutEvents(fn () => Payment::create([
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
        ];
    }
}
