<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Organization;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use Tests\Feature\Authorization\AuthorizationTestCase;

class OrganizationContextGuardTest extends AuthorizationTestCase
{
    public function test_zero_active_organizations_blocks_manual_invoice_form_without_field_error(): void
    {
        Organization::query()->update(['is_active' => false]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Нет активных организаций.')
            ->assertSeeText('Для создания договора или инвойса сначала активируйте организацию.')
            ->assertDontSee('data-testid="invoice-create-form-workspace"', false)
            ->assertDontSeeText('Не настроена активная организация.')
            ->assertSeeText('Обратитесь к администратору.');
    }

    public function test_zero_active_organizations_gives_administrator_a_link_to_organization_admin(): void
    {
        Organization::query()->update(['is_active' => false]);
        $user = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $user->assignRole(SystemRole::Administrator->value);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Перейти к организациям')
            ->assertSee(route('admin.organizations.index'), false)
            ->assertDontSeeText('Обратитесь к администратору.');
    }

    public function test_zero_active_organizations_has_a_clear_azerbaijani_topbar_and_guard_state(): void
    {
        Organization::query()->update(['is_active' => false]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->withSession(['locale' => 'az'])
            ->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Aktiv təşkilat yoxdur')
            ->assertSeeText('Müqavilə və ya invoys yaratmaq üçün əvvəlcə təşkilatı aktivləşdirin.')
            ->assertSeeText('Administratorla əlaqə saxlayın.');
    }

    public function test_multiple_active_organizations_without_history_block_invoice_and_contract_forms(): void
    {
        $first = Organization::query()->firstOrFail();
        $first->update(['name' => 'Zero Line', 'invoice_number_code' => 'ZL', 'is_active' => true]);
        Organization::query()->create([
            'name' => 'Maksim Ermakov',
            'invoice_number_code' => 'ME',
            'is_active' => true,
        ]);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::ContractsCreate->value,
        ]);

        $this->withSession(['active_organization_id' => null])
            ->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Выберите организацию в верхней панели, чтобы продолжить.')
            ->assertDontSee('data-testid="invoice-create-form-workspace"', false);

        $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSeeText('Выберите организацию в верхней панели, чтобы продолжить.')
            ->assertDontSee('data-testid="contract-form-workspace"', false);
    }

    public function test_after_topbar_selection_manual_invoice_form_uses_selected_context(): void
    {
        $first = Organization::query()->firstOrFail();
        $first->update(['name' => 'Zero Line', 'invoice_number_code' => 'ZL', 'is_active' => true]);
        $second = Organization::query()->create([
            'name' => 'Maksim Ermakov',
            'invoice_number_code' => 'ME',
            'is_active' => true,
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('organization-context.update'), ['organization_id' => $second->id]);

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('data-testid="invoice-create-form-workspace"', false)
            ->assertSeeText('Maksim Ermakov')
            ->assertSeeText('/ME-26');
    }

    public function test_company_context_invoice_create_is_guarded_without_active_organization(): void
    {
        Organization::query()->update(['is_active' => false]);
        $company = Company::query()->create(['name' => 'Global company', 'status' => 'active']);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->get(route('invoices.create', ['company_id' => $company->id]))
            ->assertOk()
            ->assertSeeText('Нет активных организаций.')
            ->assertDontSee('data-testid="invoice-create-form-workspace"', false);
    }

    public function test_contract_bound_invoice_rejects_inactive_contract_issuer_before_form(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update(['name' => 'Zero Line', 'invoice_number_code' => 'ZL', 'is_active' => false]);
        $company = Company::query()->create(['name' => 'Contract company', 'status' => 'active']);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $organization->id,
            'contract_number' => 'CTR-INACTIVE-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->get(route('invoices.create', ['contract_id' => $contract->id]))
            ->assertOk()
            ->assertSeeText('Нельзя создать инвойс: организация договора неактивна.')
            ->assertDontSee('data-testid="invoice-create-form-workspace"', false);
    }
}
