<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class CompanyAuthorizationTest extends AuthorizationTestCase
{
    public function test_company_read_routes_and_autocomplete_require_view_permission(): void
    {
        $company = $this->company('SECRET-COMPANY-NAME');
        $company->forceFill(['voen' => 'SECRET-VOEN-9031'])->save();
        $this->actingAsPermissions();

        $this->get(route('companies.index'))->assertForbidden();
        $this->get(route('companies.show', $company))->assertForbidden();
        $this->getJson(route('companies.autocomplete', ['q' => 'SECRET']))
            ->assertForbidden()
            ->assertDontSee('SECRET-COMPANY-NAME')
            ->assertDontSee('SECRET-VOEN-9031')
            ->assertDontSee('"id":'.$company->id, false);
    }

    public function test_company_permissions_are_independent_and_ui_follows_each_ability(): void
    {
        $company = $this->company('Permission UI Company');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertDontSee(route('companies.create'), false)
            ->assertDontSee(route('companies.edit', $company), false);
        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee(route('companies.edit', $company), false)
            ->assertDontSee(route('companies.destroy', $company), false);
        $this->get(route('companies.create'))->assertForbidden();
        $this->get(route('companies.edit', $company))->assertForbidden();

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesCreate->value,
            PermissionName::CompaniesUpdate->value,
            PermissionName::CompaniesDelete->value,
        ]);

        $this->get(route('companies.index'))
            ->assertSee(route('companies.create'), false)
            ->assertSee(route('companies.edit', ['company' => $company, 'origin' => 'index']), false);
        $this->get(route('companies.show', $company))
            ->assertSee(route('companies.edit', ['company' => $company, 'origin' => 'show']), false)
            ->assertSee(route('companies.destroy', $company), false);
    }

    public function test_custom_role_is_authorized_by_company_permission_not_role_name(): void
    {
        $company = $this->company('Custom Role Company');
        $user = $this->actingAsCustomRole([PermissionName::CompaniesView->value]);

        $this->get(route('companies.index'))->assertOk();
        $this->get(route('companies.show', $company))->assertOk();
        $this->assertFalse($user->hasRole('viewer'));
        $this->assertFalse($user->hasRole('administrator'));
        $this->assertFalse($user->can(PermissionName::CompaniesUpdate->value));
    }

    public function test_navigation_is_hidden_without_company_view_permission(): void
    {
        $this->actingAsPermissions();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('companies.index').'"', false);
    }

    public function test_viewer_reads_companies_without_financial_index_disclosure(): void
    {
        $company = $this->company('Viewer Company');
        $invoice = $this->invoice('issued', 'VIEWER-DEBT');
        $invoice->forceFill([
            'company_id' => $company->id,
            'total_amount' => '173.21',
        ])->saveQuietly();
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findByName('viewer'));
        $this->actingAs($viewer, 'web');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->get(route('companies.index', ['sort' => 'debt']))
            ->assertOk()
            ->assertSee('Viewer Company')
            ->assertDontSee('company-debt-column')
            ->assertDontSee('company-debt-value')
            ->assertDontSee('173.21')
            ->assertDontSee('173,21');

        $this->assertSame('name', $response->viewData('sort'));
        $this->assertFalse($response->viewData('canViewFinancials'));
        $this->assertFalse($response->viewData('companies')->first()->relationLoaded('creditBalance'));
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'invoices')
                || str_contains($sql, 'payments')
                || str_contains($sql, 'credit_balances')
        ));
        $this->get(route('companies.show', $company))->assertOk()->assertDontSee('Финансы');
    }

    public function test_accountant_sees_existing_financial_index_summary_and_sort(): void
    {
        $company = $this->company('Accountant Company');
        $invoice = $this->invoice('issued', 'ACCOUNTANT-DEBT');
        $invoice->forceFill([
            'company_id' => $company->id,
            'total_amount' => '173.21',
        ])->saveQuietly();
        $accountant = User::factory()->create();
        $accountant->assignRole(Role::findByName('accountant'));
        $this->actingAs($accountant, 'web');

        $response = $this->get(route('companies.index', [
            'sort' => 'debt',
            'direction' => 'desc',
        ]))->assertOk()
            ->assertSee('company-debt-column')
            ->assertSee('company-debt-value')
            ->assertSee('173.21');

        $this->assertSame('debt', $response->viewData('sort'));
        $this->assertTrue($response->viewData('canViewFinancials'));
        $this->assertFalse($accountant->can(PermissionName::CompaniesCreate->value));
        $this->assertFalse($accountant->can(PermissionName::CompanyContactsCreate->value));
    }

    public function test_forbidden_debt_sort_is_removed_from_pagination_urls(): void
    {
        foreach (range(1, 12) as $number) {
            $this->company(sprintf('Pagination Safe %02d', $number));
        }
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $response = $this->get(route('companies.index', [
            'sort' => 'debt',
            'direction' => 'desc',
            'search' => 'Pagination Safe',
        ]))->assertOk()
            ->assertDontSee('company-debt-column')
            ->assertDontSee('sort=debt', false)
            ->assertDontSee('sort%3Ddebt', false)
            ->assertSee('search=Pagination%20Safe', false);

        $this->assertSame('name', $response->viewData('sort'));
        $this->assertSame('desc', $response->viewData('direction'));
        $this->assertTrue($response->viewData('companies')->hasPages());
    }

    public function test_allowed_debt_sort_is_preserved_in_pagination_urls(): void
    {
        foreach (range(1, 12) as $number) {
            $this->company(sprintf('Pagination Financial %02d', $number));
        }
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);

        $response = $this->get(route('companies.index', [
            'sort' => 'debt',
            'direction' => 'desc',
            'search' => 'Pagination Financial',
        ]))->assertOk()
            ->assertSee('company-debt-column')
            ->assertSee('sort=debt', false)
            ->assertSee('search=Pagination%20Financial', false);

        $this->assertSame('debt', $response->viewData('sort'));
        $this->assertTrue($response->viewData('companies')->hasPages());
    }

    public function test_company_show_contract_details_and_create_action_use_independent_permissions(): void
    {
        $company = $this->company('Contract Disclosure Company');
        $contract = $this->contract($company);
        $contract->forceFill([
            'contract_number' => 'SECRET-CONTRACT-9082',
            'start_date' => '2042-03-14',
            'end_date' => '2043-04-15',
            'status' => 'terminated',
        ])->save();
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $withoutContracts = $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee('SECRET-CONTRACT-9082')
            ->assertDontSee('14/03/2042')
            ->assertDontSee('15/04/2043')
            ->assertDontSee('terminated')
            ->assertDontSee(route('contracts.show', $contract), false)
            ->assertDontSee(route('companies.contracts.create', $company), false);
        $this->assertFalse($withoutContracts->viewData('company')->relationLoaded('contracts'));

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);
        $withRead = $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('SECRET-CONTRACT-9082')
            ->assertSee('14/03/2042')
            ->assertSee('15/04/2043')
            ->assertSee('terminated');
        $this->assertTrue($withRead->viewData('company')->relationLoaded('contracts'));

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsCreate->value,
        ]);
        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee('SECRET-CONTRACT-9082')
            ->assertDontSee('14/03/2042')
            ->assertDontSee('15/04/2043')
            ->assertDontSee('terminated')
            ->assertDontSee(route('contracts.show', $contract), false)
            ->assertSee(route('companies.contracts.create', $company), false);
    }

    public function test_delete_button_requires_permission_and_deletable_business_state(): void
    {
        $empty = $this->company('Empty deletable company');
        $blocked = $this->company('Blocked company');
        $this->contact($blocked);
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesDelete->value,
        ]);

        $this->get(route('companies.show', $empty))
            ->assertOk()
            ->assertSee(route('companies.destroy', $empty), false);
        $this->get(route('companies.show', $blocked))
            ->assertOk()
            ->assertDontSee(route('companies.destroy', $blocked), false);
    }

    #[DataProvider('blockingDependencyProvider')]
    public function test_custom_role_cannot_delete_company_with_blocking_dependency(
        string $dependency
    ): void {
        $company = $this->company('Blocked by '.$dependency);
        [$table, $id] = $this->createBlockingDependency($company, $dependency);
        $this->actingAsCustomRole([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesDelete->value,
        ]);

        $this->delete(route('companies.destroy', $company))
            ->assertRedirect(route('companies.show', $company))
            ->assertSessionHas('error', 'Невозможно удалить компанию, пока с ней связаны контакты, договоры или финансовые данные.');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertDatabaseHas($table, ['id' => $id]);
    }

    public static function blockingDependencyProvider(): array
    {
        return [
            'contact' => ['contact'],
            'contract' => ['contract'],
            'invoice' => ['invoice'],
            'payment' => ['payment'],
            'credit balance' => ['credit_balance'],
        ];
    }

    public function test_administrator_cannot_bypass_delete_business_rule_or_partially_delete_contacts(): void
    {
        $company = $this->company('Administrator blocked company');
        $contact = $this->contact($company, 'MUST-SURVIVE-CONTACT');
        $balance = $company->creditBalance()->create(['amount' => '10.00']);
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');

        $this->delete(route('companies.destroy', $company))
            ->assertRedirect(route('companies.show', $company))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('credit_balances', [
            'id' => $balance->id,
            'company_id' => $company->id,
            'amount' => '10.00',
        ]);
    }

    public function test_custom_role_with_delete_permission_can_delete_empty_company(): void
    {
        $company = $this->company('Empty custom delete company');
        $this->actingAsCustomRole([PermissionName::CompaniesDelete->value]);

        $this->delete(route('companies.destroy', $company))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    /** @return array{string, int} */
    private function createBlockingDependency(Company $company, string $dependency): array
    {
        if ($dependency === 'contact') {
            $contact = $this->contact($company, 'Blocking Contact');

            return ['company_contacts', $contact->id];
        }

        if ($dependency === 'contract') {
            $contract = $this->contract($company);

            return ['contracts', $contract->id];
        }

        if ($dependency === 'credit_balance') {
            $balance = $company->creditBalance()->create(['amount' => '0.00']);

            return ['credit_balances', $balance->id];
        }

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'invoice_number' => 'BLOCKING-'.strtoupper($dependency).'-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);

        if ($dependency === 'invoice') {
            return ['invoices', $invoice->id];
        }

        $payment = Payment::withoutEvents(fn () => Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $company->id,
            'payment_date' => '2026-07-15',
            'amount' => '25.00',
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]));

        return ['payments', $payment->id];
    }
}
