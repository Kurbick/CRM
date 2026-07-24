<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthenticatedTestCase as TestCase;

class CompanyContextNavigationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_invalid_or_external_context_is_ignored_and_entity_owns_company_context(): void
    {
        [$company, $contract] = $this->companyAndContract();
        $this->get(route('contracts.show', ['contract' => $contract, 'origin' => 'company', 'tab' => 'https://evil.test']))
            ->assertOk()->assertSee('Назад к договорам')->assertDontSee('Назад к Context Company');
        $this->assertSame($company->id, $contract->company_id);
    }

    /** @return array{Company, Contract} */
    private function companyAndContract(): array
    {
        $company = Company::create(['name' => 'Context Company', 'status' => 'active', 'invoice_mode' => 'separate']);
        $contract = Contract::create(['company_id' => $company->id, 'contract_number' => 'CTX-1', 'start_date' => '2026-01-01', 'status' => 'active']);
        return [$company, $contract];
    }
}
