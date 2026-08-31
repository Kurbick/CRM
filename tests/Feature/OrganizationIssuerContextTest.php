<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Invoices\CreateInvoice;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\InvoiceNumberCounter;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Authorization\AuthorizationTestCase;

class OrganizationIssuerContextTest extends AuthorizationTestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_switch_only_between_active_organizations(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $user = $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee($zeroLine->name)
            ->assertSee('name="organization_id" value="'.$kurban->id.'"', false);

        $this->from(route('contracts.index'))
            ->post(route('organization-context.update'), ['organization_id' => $kurban->id])
            ->assertRedirect(route('contracts.index'));

        $this->assertSame($kurban->id, session('active_organization_id'));
        $this->assertSame($kurban->id, $user->fresh()->last_organization_id);

        $kurban->update(['is_active' => false]);
        $this->post(route('organization-context.update'), ['organization_id' => $kurban->id])
            ->assertStatus(422);
        $this->assertSame($zeroLine->id, session('active_organization_id'));
    }

    public function test_last_selected_organization_is_restored_per_user_after_session_expiry(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $user = $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->post(route('organization-context.update'), ['organization_id' => $zeroLine->id]);
        $this->post(route('organization-context.update'), ['organization_id' => $kurban->id]);

        $this->withSession(['active_organization_id' => null])
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee($kurban->name);

        $this->assertSame($kurban->id, session('active_organization_id'));
        $this->assertSame($kurban->id, $user->fresh()->last_organization_id);
    }

    public function test_users_remember_different_organizations_independently(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $firstUser = $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $this->post(route('organization-context.update'), ['organization_id' => $zeroLine->id]);

        $secondUser = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $secondUser->givePermissionTo(PermissionName::ContractsView->value);
        $this->actingAs($secondUser, 'web');
        $this->post(route('organization-context.update'), ['organization_id' => $kurban->id]);

        $this->assertSame($zeroLine->id, $firstUser->fresh()->last_organization_id);
        $this->assertSame($kurban->id, $secondUser->fresh()->last_organization_id);
    }

    public function test_invalid_remembered_organization_is_cleared_and_single_active_fallback_is_safe(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $user = $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $user->forceFill(['last_organization_id' => $kurban->id])->save();
        $kurban->update(['is_active' => false]);

        $this->withSession(['active_organization_id' => null])
            ->get(route('contracts.index'))
            ->assertOk();

        $this->assertSame($zeroLine->id, session('active_organization_id'));
        $this->assertSame($zeroLine->id, $user->fresh()->last_organization_id);
    }

    public function test_deleted_remembered_organization_is_null_on_delete(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $user = $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $user->forceFill(['last_organization_id' => $kurban->id])->save();
        $kurban->delete();

        $this->assertNull($user->fresh()->last_organization_id);
    }

    public function test_companies_are_global_but_contract_lists_follow_active_organization(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $company = Company::query()->create(['name' => 'Skycell', 'status' => 'active']);
        $zeroContract = $this->makeContract($company, $zeroLine, 'ZERO-CONTRACT');
        $kurbanContract = $this->makeContract($company, $kurban, 'KURBAN-CONTRACT');

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);

        $zeroResponse = $this->withSession(['active_organization_id' => $zeroLine->id])
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('ZERO-CONTRACT')
            ->assertDontSee('KURBAN-CONTRACT');
        $this->assertSame($zeroLine->id, $zeroResponse->viewData('contracts')->first()->issuer_organization_id);

        $this->get(route('contracts.show', $kurbanContract))
            ->assertOk()
            ->assertSee('KURBAN-CONTRACT');

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSee('Skycell');

        $this->post(route('organization-context.update'), ['organization_id' => $kurban->id]);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('KURBAN-CONTRACT')
            ->assertDontSee('ZERO-CONTRACT');
        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSee('Skycell');

        $this->assertSame($zeroLine->id, $zeroContract->issuer_organization_id);
    }

    public function test_active_context_requires_explicit_selection_when_multiple_organizations_are_active(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $this->makeContract(
            Company::query()->create(['name' => 'Fallback company', 'status' => 'active']),
            $zeroLine,
            'FALLBACK-CONTRACT',
        );

        $this->withSession(['active_organization_id' => null]);
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);
        $this
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSeeText('Выберите организацию')
            ->assertDontSee('FALLBACK-CONTRACT');

        $this->assertNull(session('active_organization_id'));

        $this->withSession(['locale' => 'az'])
            ->get(route('contracts.index'))
            ->assertSeeText('Təşkilat seçin');

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->get(route('contracts.index'))
            ->assertSee('FALLBACK-CONTRACT');
    }

    public function test_active_context_automatically_selects_the_only_active_organization(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $kurban->update(['is_active' => false]);

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);
        $this->withSession(['active_organization_id' => null])
            ->get(route('contracts.index'))
            ->assertOk();

        $this->assertSame($zeroLine->id, session('active_organization_id'));
    }

    public function test_default_organization_column_is_removed_from_the_final_schema(): void
    {
        $this->assertFalse(Schema::hasColumn('organizations', 'is_default'));
        $this->assertTrue(Schema::hasColumn('users', 'last_organization_id'));
    }

    public function test_api_contract_create_requires_explicit_organization_when_multiple_are_active(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $company = Company::query()->create(['name' => 'API organization selection', 'status' => 'active']);
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->postJson(route('api.companies.contracts.store', $company), [
            'contract_number' => 'API-SELECTION-'.uniqid(),
            'start_date' => '2026-08-01',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization');
    }

    public function test_api_does_not_use_a_web_users_last_organization_as_an_implicit_issuer(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $company = Company::query()->create(['name' => 'API user context', 'status' => 'active']);
        $user = $this->actingAsPermissions([PermissionName::ContractsCreate->value]);
        $user->forceFill(['last_organization_id' => $zeroLine->id])->save();

        $this->postJson(route('api.companies.contracts.store', $company), [
            'contract_number' => 'API-WEB-CONTEXT-'.uniqid(),
            'start_date' => '2026-08-01',
            'status' => 'active',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization');

        $this->assertDatabaseMissing('contracts', [
            'company_id' => $company->id,
        ]);
        $this->assertTrue($kurban->is_active);
    }

    public function test_contract_issuer_is_authoritative_and_numbering_counters_are_independent(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $company = Company::query()->create(['name' => 'Shared customer', 'status' => 'active']);
        $zeroContract = $this->makeContract($company, $zeroLine, 'ZERO-CONTRACT');
        $kurbanContract = $this->makeContract($company, $kurban, 'KURBAN-CONTRACT');

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $create = fn (Contract $contract): Invoice => app(CreateInvoice::class)->execute(
            $company,
            $contract,
            [
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-31',
            ],
            [['description' => 'Manual service', 'amount' => '10.00']],
        );

        $zeroInvoice = $create($zeroContract);
        $kurbanInvoice = $create($kurbanContract);
        $secondZeroInvoice = $create($zeroContract);

        $this->assertSame('1/ZL-26', $zeroInvoice->invoice_number);
        $this->assertSame('1/KB-26', $kurbanInvoice->invoice_number);
        $this->assertSame('2/ZL-26', $secondZeroInvoice->invoice_number);
        $this->assertSame($zeroLine->id, $zeroInvoice->issuer_organization_id);
        $this->assertSame($kurban->id, $kurbanInvoice->issuer_organization_id);
        $this->assertSame(2, InvoiceNumberCounter::query()->where('organization_id', $zeroLine->id)->value('last_sequence'));
        $this->assertSame(1, InvoiceNumberCounter::query()->where('organization_id', $kurban->id)->value('last_sequence'));
    }

    public function test_credit_balance_for_one_organization_cannot_be_applied_to_another_invoice(): void
    {
        [$zeroLine, $kurban] = $this->twoOrganizations();
        $company = Company::query()->create(['name' => 'Credit customer', 'status' => 'active']);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $kurban->id,
            'invoice_number' => 'KB-CREDIT-1',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
        $zeroBalance = CreditBalance::query()->create([
            'company_id' => $company->id,
            'organization_id' => $zeroLine->id,
            'amount' => '50.00',
        ]);

        try {
            app(ApplyCreditToInvoice::class)->executeManual(
                $invoice,
                5000,
                0,
                10000,
            );
            $this->fail('A credit balance from another organization must not be applied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credit_amount', $exception->errors());
        }

        $this->assertSame('50.00', $zeroBalance->fresh()->amount);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    /** @return array{0: Organization, 1: Organization} */
    private function twoOrganizations(): array
    {
        $zeroLine = Organization::query()->firstOrFail();
        $zeroLine->update([
            'name' => 'Zero Line',
            'invoice_number_code' => 'ZL',
            'is_active' => true,
        ]);
        $kurban = Organization::query()->create([
            'name' => 'Kurban',
            'invoice_number_code' => 'KB',
            'is_active' => true,
        ]);

        return [$zeroLine->fresh(), $kurban];
    }

    private function makeContract(Company $company, Organization $organization, string $number): Contract
    {
        return Contract::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $organization->id,
            'contract_number' => $number,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }
}
