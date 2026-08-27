<?php

namespace Tests\Feature\Localization;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Invoice;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\FinancialTestCase;

class CompanyContactLocalizationTest extends FinancialTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesCreate->value,
            PermissionName::CompaniesUpdate->value,
            PermissionName::CompaniesDelete->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::CompanyContactsCreate->value,
            PermissionName::CompanyContactsUpdate->value,
            PermissionName::CompanyContactsDelete->value,
            PermissionName::ContractsView->value,
        ]);
    }

    public function test_company_index_and_show_keep_the_approved_russian_presentation(): void
    {
        $company = $this->companyContext();

        $this->withSession(['locale' => 'ru']);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSeeText('Компании')
            ->assertSeeText('Управление клиентами и реквизитами')
            ->assertSeeText('Активна');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSeeText('Активна')
            ->assertSeeText('Финансы')
            ->assertSeeText('Оплачено')
            ->assertSeeText('Баланс компании')
            ->assertSeeText('Контакты')
            ->assertSeeText('Договоры')
            ->assertSeeText('Инвойсы')
            ->assertSeeText('Платежи')
            ->assertSeeText('Активность');
    }

    public function test_company_and_contact_web_ui_uses_approved_azerbaijani_terminology(): void
    {
        $company = $this->companyContext();
        $contact = $company->contacts()->create([
            'first_name' => 'Aysel',
            'last_name' => 'Mammadova',
            'role' => 'manager',
        ]);

        $this->withSession(['locale' => 'az']);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSeeText('Şirkətlər')
            ->assertSeeText('Aktiv')
            ->assertSee('title="Düzəliş et"', false);

        $this->get(route('companies.create'))
            ->assertOk()
            ->assertSeeText('Yeni şirkət')
            ->assertSeeText('Əsas məlumat');

        $this->get(route('companies.edit', $company))
            ->assertOk()
            ->assertSeeText('Şirkətə düzəliş et')
            ->assertSeeText('Yadda saxla');

        $show = $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSeeText('Aktiv')
            ->assertSeeText('Ödənilib')
            ->assertSeeText('Şirkətin balansı')
            ->assertSeeText('Kontaktlar')
            ->assertSeeText('Müqavilələr')
            ->assertSeeText('İnvoyslar')
            ->assertSeeText('Ödənişlər')
            ->assertSeeText('Fəaliyyət')
            ->assertSeeText('Kontakt əlavə et')
            ->assertSee('aria-label="Kontakta düzəliş et"', false);
        $this->assertStringNotContainsString('Redakt', $show->getContent());

        $this->get(route('companies.contacts.create', $company))
            ->assertOk()
            ->assertSeeText('Yeni kontakt')
            ->assertSeeText('Ad')
            ->assertSeeText('Vəzifə');

        $this->get(route('contacts.edit', $contact))
            ->assertOk()
            ->assertSeeText('Kontakta düzəliş et')
            ->assertSeeText('Kontaktı sil')
            ->assertSeeText('Yadda saxla');
    }

    public function test_company_context_permissions_and_navigation_are_unchanged_by_locale(): void
    {
        $company = $this->companyContext();

        $this->withSession(['locale' => 'az']);

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee(e(route('companies.contacts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contacts'])), false);

        $this->authenticatedUser->revokePermissionTo(PermissionName::CompanyContactsCreate->value);

        $this->get(route('companies.contacts.create', $company))->assertForbidden();
        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee(e(route('companies.contacts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contacts'])), false);
    }

    private function companyContext(): Company
    {
        $company = Company::query()->create([
            'name' => 'Localization Company',
            'status' => 'active',
            'type' => 'company',
            'voen' => '1234567890',
        ]);

        $company->creditBalance()->create(['amount' => '25.00']);

        Invoice::query()->create([
            'company_id' => $company->id,
            'invoice_number' => 'LOC-INV-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);

        return $company;
    }
}
