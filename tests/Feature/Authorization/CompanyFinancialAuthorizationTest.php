<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;

class CompanyFinancialAuthorizationTest extends AuthorizationTestCase
{
    public function test_company_card_without_financial_permission_omits_data_and_relations(): void
    {
        $invoice = $this->invoice('issued', 'HIDDEN-FINANCE');
        $this->payment($invoice, 'pending', 'HIDDEN-PAYMENT-DETAIL');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $response = $this->get(route('companies.show', $invoice->company))->assertOk();

        $response->assertViewMissing('stats')
            ->assertViewMissing('subscriptionPeriodDebts')
            ->assertDontSee('Финансы')
            ->assertDontSee($invoice->invoice_number)
            ->assertDontSee('HIDDEN-PAYMENT-DETAIL');

        $company = $response->viewData('company');
        $this->assertFalse($company->relationLoaded('invoices'));
        $this->assertFalse($company->relationLoaded('payments'));
        $this->assertFalse($company->relationLoaded('creditBalance'));
    }

    public function test_financial_permission_shows_aggregates_without_loading_lists(): void
    {
        $invoice = $this->invoice('issued', 'AGGREGATES-ONLY');
        $this->payment($invoice, 'pending', 'PRIVATE-PAYMENT-ROW');
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);

        $response = $this->get(route('companies.show', $invoice->company))->assertOk();

        $response->assertViewHas('stats')
            ->assertViewMissing('subscriptionPeriodDebts')
            ->assertSee('Финансы')
            ->assertSee('Общий долг')
            ->assertDontSee($invoice->invoice_number)
            ->assertDontSee('PRIVATE-PAYMENT-ROW')
            ->assertDontSee('Задолженности');

        $company = $response->viewData('company');
        $this->assertFalse($company->relationLoaded('invoices'));
        $this->assertFalse($company->relationLoaded('payments'));
        $this->assertTrue($company->relationLoaded('creditBalance'));
    }

    public function test_invoice_list_requires_financials_and_invoice_view(): void
    {
        $invoice = $this->invoice('issued', 'VISIBLE-INVOICE-LIST');
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::InvoicesView->value,
        ]);

        $response = $this->get(route('companies.show', $invoice->company))->assertOk();

        $response->assertSee('Финансы')
            ->assertSee('Задолженности')
            ->assertSee($invoice->invoice_number)
            ->assertSee(route('invoices.show', [
                'invoice' => $invoice,
                'origin' => 'company',
                'tab' => 'invoices',
            ]))
            ->assertDontSee('Платежи (');
        $this->assertTrue($response->viewData('company')->relationLoaded('invoices'));
        $this->assertFalse($response->viewData('company')->relationLoaded('payments'));
    }

    public function test_payment_details_require_financials_and_payments_view_without_leaking_invoice_links(): void
    {
        $invoice = $this->invoice('issued', 'HIDDEN-INVOICE-LINK');
        $this->payment($invoice, 'pending', 'VISIBLE-PAYMENT-DETAIL');
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::PaymentsView->value,
        ]);

        $response = $this->get(route('companies.show', [
            'company' => $invoice->company,
            'tab' => 'payments',
        ]))->assertOk();

        $response->assertSee('VISIBLE-PAYMENT-DETAIL')
            ->assertSee('Платежи (1)')
            ->assertDontSee($invoice->invoice_number)
            ->assertDontSee(route('invoices.show', $invoice), false);

        $company = $response->viewData('company');
        $this->assertTrue($company->relationLoaded('payments'));
        $this->assertFalse($company->relationLoaded('invoices'));
        $this->assertFalse($company->payments->firstOrFail()->relationLoaded('invoice'));
    }

    public function test_administrator_receives_company_financials_through_bypass(): void
    {
        $invoice = $this->invoice('issued', 'ADMIN-FINANCE');
        $this->payment($invoice, 'pending', 'ADMIN-PAYMENT');
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');

        $this->get(route('companies.show', $invoice->company))
            ->assertOk()
            ->assertSee('Финансы')
            ->assertSee($invoice->invoice_number)
            ->assertSee('ADMIN-PAYMENT');
    }
}
