<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FinancialTestCase as TestCase;

class CompanyContextNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompanyContactsCreate->value,
            PermissionName::CompanyContactsUpdate->value,
            PermissionName::ContractsView->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::InvoicesView->value,
        ]);
    }

    public function test_invoice_company_context_returns_to_invoices_tab_and_fallback_stays_index(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create(['company_id' => $company->id, 'contract_id' => $contract->id, 'invoice_number' => 'INV-CONTEXT', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-10', 'total_amount' => '0.00', 'status' => 'draft']);

        $this->get(route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee('Назад к Context Company')
            ->assertSee(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertSee(route('invoices.edit', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']));

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Назад к списку');
    }

    public function test_activity_invoice_link_and_delete_return_to_the_same_activity_tab(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-ACTIVITY-CONTEXT',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $invoice->lines()->create(['description' => 'Activity context line', 'amount' => '100.00']);
        app(CompanyActivityRecorder::class)->record(
            $company,
            CompanyActivityEventType::InvoiceCreated,
            CompanyActivityCategory::Invoices,
            CompanyActivityVisibilityScope::Financials,
            subject: $invoice,
            metadata: [
                ...CompanyActivitySnapshot::invoice($invoice, $contract),
                'invoice_id' => $invoice->id,
            ],
        );

        $activityInvoiceUrl = route('invoices.show', [
            'invoice' => $invoice,
            'origin' => 'company',
            'tab' => 'activity',
        ]);
        $this->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee($activityInvoiceUrl);

        $this->get($activityInvoiceUrl)
            ->assertOk()
            ->assertSee(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertSee(route('invoices.edit', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'activity']))
            ->assertSee(route('invoices.destroy', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'activity']));
        $this->get(route('invoices.edit', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('name="tab" value="activity"', false);

        $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'origin' => 'company',
            'tab' => 'activity',
        ]))->assertRedirect(route('companies.show', ['company' => $company, 'tab' => 'activity']));
    }

    public function test_company_invoice_link_and_delete_return_to_the_invoices_tab(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-INVOICES-CONTEXT',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $invoice->lines()->create(['description' => 'Invoices context line', 'amount' => '100.00']);
        $invoiceUrl = route('invoices.show', [
            'invoice' => $invoice,
            'origin' => 'company',
            'tab' => 'invoices',
        ]);

        $this->get(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee($invoiceUrl);

        $this->get($invoiceUrl)
            ->assertOk()
            ->assertSee(route('invoices.destroy', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'invoices']));

        $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'origin' => 'company',
            'tab' => 'invoices',
        ]))->assertRedirect(route('companies.show', ['company' => $company, 'tab' => 'invoices']));
    }

    public function test_forged_company_context_never_redirects_to_the_supplied_company(): void
    {
        [$companyA, $contract] = $this->companyAndContract();
        [$companyB] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $companyA->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-FORGED-CONTEXT',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);

        $response = $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'origin' => 'company',
            'company_id' => $companyB->id,
            'tab' => 'invoices',
        ]));

        $response->assertRedirect(route('companies.show', ['company' => $companyA, 'tab' => 'invoices']));
        $this->assertStringNotContainsString('/companies/'.$companyB->id, (string) $response->headers->get('Location'));
    }

    public function test_unknown_company_context_uses_the_global_invoice_fallback(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-INVALID-CONTEXT',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);

        $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'origin' => 'unknown',
            'tab' => 'activity',
            'return_to' => 'https://evil.test',
        ]))->assertRedirect(route('invoices.index'));
    }

    public function test_company_context_does_not_bypass_company_authorization(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-UNAUTHORIZED-CONTEXT',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->givePermissionTo([
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesDelete->value,
        ]);
        $this->actingAs($user, 'web');

        $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'origin' => 'company',
            'tab' => 'activity',
        ]))->assertRedirect(route('invoices.index'));
    }

    public function test_invoice_payment_tab_context_returns_to_payments_without_payment_show_route(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $invoice = Invoice::create(['company_id' => $company->id, 'contract_id' => $contract->id, 'invoice_number' => 'INV-PAYMENT-CONTEXT', 'issue_date' => '2026-07-01', 'due_date' => '2026-07-10', 'total_amount' => '0.00', 'status' => 'draft']);
        $this->get(route('invoices.show', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'payments']))
            ->assertOk()
            ->assertSee(route('companies.show', ['company' => $company, 'tab' => 'payments']));

        $this->get(route('invoices.edit', ['invoice' => $invoice, 'origin' => 'company', 'tab' => 'payments']))
            ->assertOk()
            ->assertSee('name="tab" value="payments"', false);
    }

    public function test_contract_context_survives_show_edit_and_update(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $query = ['origin' => 'company', 'tab' => 'contracts'];

        $this->get(route('contracts.show', ['contract' => $contract, ...$query]))
            ->assertOk()->assertSee('Назад к Context Company')
            ->assertSee(route('companies.show', ['company' => $company, 'tab' => 'contracts']));
        $this->get(route('contracts.edit', ['contract' => $contract, ...$query]))
            ->assertOk()->assertSee('name="origin" value="company"', false);
        $this->put(route('contracts.update', $contract), [
            'contract_number' => 'CTX-UPDATED', 'start_date' => '2026-01-01', 'status' => 'active', ...$query,
        ])->assertRedirect(route('contracts.show', ['contract' => $contract, ...$query]));
    }

    public function test_contact_create_and_update_preserve_contacts_tab(): void
    {
        [$company] = $this->companyAndContract();
        $query = ['origin' => 'company', 'tab' => 'contacts'];
        $this->get(route('companies.contacts.create', ['company' => $company, ...$query]))
            ->assertOk()->assertSee('name="origin" value="company"', false);
        $this->post(route('companies.contacts.store', $company), ['first_name' => 'A', ...$query])
            ->assertRedirect(route('companies.show', ['company' => $company, 'tab' => 'contacts']));
        $contact = CompanyContact::query()->firstOrFail();
        $this->put(route('contacts.update', $contact), ['first_name' => 'B', ...$query])
            ->assertRedirect(route('companies.show', ['company' => $company, 'tab' => 'contacts']));
    }

    public function test_company_contacts_use_canonical_icon_actions_with_existing_authorization(): void
    {
        [$company] = $this->companyAndContract();
        $contact = $company->contacts()->create([
            'first_name' => 'Visible',
            'last_name' => 'Contact',
            'role' => 'other',
        ]);
        $showUrl = route('companies.show', ['company' => $company, 'tab' => 'contacts']);
        $editUrl = route('contacts.edit', ['contact' => $contact, 'origin' => 'company', 'tab' => 'contacts']);
        $deleteUrl = route('contacts.destroy', $contact);

        $withoutDelete = $this->get($showUrl)
            ->assertOk()
            ->assertSee($editUrl)
            ->assertSee('class="crm-table-icon-action crm-table-icon-action-primary"', false)
            ->assertSee('aria-label="Редактировать контакт"', false)
            ->assertSee('title="Редактировать"', false)
            ->assertDontSee('action="'.$deleteUrl.'"', false)
            ->assertDontSee('aria-label="Удалить контакт"', false);

        $this->assertStringNotContainsString('Редакт.</a>', $withoutDelete->getContent());
        $this->assertStringNotContainsString('crm-table-action-link">Редакт.', $withoutDelete->getContent());

        $this->authenticatedUser->givePermissionTo(PermissionName::CompanyContactsDelete->value);
        $withDelete = $this->get($showUrl)
            ->assertOk()
            ->assertSee($editUrl)
            ->assertSee($deleteUrl, false)
            ->assertSee('action="'.$deleteUrl.'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('class="crm-table-icon-action crm-table-icon-action-primary"', false)
            ->assertSee('class="crm-table-icon-action crm-table-icon-action-danger"', false)
            ->assertSee('aria-label="Удалить контакт"', false)
            ->assertSee('<path d="M4 7h16" />', false)
            ->assertSee('stroke="currentColor"', false);

        $this->assertStringNotContainsString('Редакт.</a>', $withDelete->getContent());
        $this->assertStringNotContainsString('>Удалить</button>', $withDelete->getContent());

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionName::CompaniesView->value);
        $this->actingAs($viewer, 'web');

        $this->get($showUrl)
            ->assertOk()
            ->assertDontSee($editUrl)
            ->assertDontSee('action="'.$deleteUrl.'"', false)
            ->assertDontSee('aria-label="Редактировать контакт"', false)
            ->assertDontSee('aria-label="Удалить контакт"', false);
    }

    public function test_invalid_or_external_context_is_ignored_and_entity_owns_company_context(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $this->get(route('contracts.show', ['contract' => $contract, 'origin' => 'company', 'tab' => 'https://evil.test']))
            ->assertOk()->assertSee('Назад к договорам')->assertDontSee('Назад к Context Company');
        $this->assertSame($company->id, $contract->company_id);
    }

    public function test_company_invoice_create_action_uses_the_shared_create_route_only_with_permission(): void
    {
        [$company] = $this->companyAndContract();
        $url = route('invoices.create', [
            'company_id' => $company,
            'origin' => 'company',
            'tab' => 'invoices',
        ]);
        $this->actingAs($this->invoiceViewer());

        $this->get(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertOk()
            ->assertDontSee($url, false);

        $this->actingAs($this->invoiceCreator());

        $this->get(route('companies.show', ['company' => $company, 'tab' => 'invoices']))
            ->assertOk()
            ->assertSee($url)
            ->assertSee('Выставить счёт');
    }

    public function test_contract_invoice_create_action_uses_the_shared_create_route_only_for_selectable_contracts(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $url = route('invoices.create', ['contract_id' => $contract->id]);

        $this->actingAs($this->invoiceViewer())
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee($url, false);

        $this->actingAs($this->invoiceCreator())
            ->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee($url, false)
            ->assertSeeInOrder(['Выставить счёт', 'Редактировать']);

        $contract->update(['status' => 'terminated']);

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee($url, false);

        $contract->update(['status' => 'active']);
        $company->update(['status' => 'suspended']);

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee($url, false);
    }

    /** @return array{Company, Contract} */
    private function companyAndContract(): array
    {
        $company = Company::create(['name' => 'Context Company', 'status' => 'active']);
        $contract = Contract::create(['company_id' => $company->id, 'contract_number' => 'CTX-'.$company->id, 'start_date' => '2026-01-01', 'status' => 'active']);

        return [$company, $contract];
    }

    private function invoiceCreator(): User
    {
        return $this->invoiceActor(true);
    }

    private function invoiceViewer(): User
    {
        return $this->invoiceActor(false);
    }

    private function invoiceActor(bool $canCreate): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::InvoicesView->value,
        ]);
        if ($canCreate) {
            $user->givePermissionTo(PermissionName::InvoicesCreate->value);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('permissions')->unsetRelation('roles');

        return $user;
    }
}
