<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainQueryRecorder;

class ApiInvoiceAuthorizationTest extends AuthorizationTestCase
{
    public function test_guest_is_stopped_before_invoice_access(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-GUEST');

        $this->getJson(route('api.invoices.show', $invoice))->assertUnauthorized();
    }

    public function test_inactive_user_is_stopped_before_invoice_access(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-INACTIVE');
        $user = User::factory()->inactive()->create();
        $user->givePermissionTo(PermissionName::InvoicesView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.invoices.show', $invoice))
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }

    public function test_password_change_user_is_stopped_before_invoice_access(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-PASSWORD');
        $user = User::factory()->requiringPasswordChange()->create();
        $user->givePermissionTo(PermissionName::InvoicesView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.invoices.show', $invoice))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_missing_and_wrong_permissions_fail_before_invoice_binding_or_queries(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-DENIED');

        foreach ([[], [PermissionName::InvoicesUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.invoices.show', $invoice)),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.invoices.show', ['invoice' => 1_000_000])),
            );

            $existing['result']->assertForbidden();
            $missing['result']->assertForbidden();
            $this->assertSame($existing['result']->status(), $missing['result']->status());
            $this->assertSame($existing['result']->json('message'), $missing['result']->json('message'));
            $this->assertSame([], $existing['records']);
            $this->assertSame([], $missing['records']);
        }
    }

    public function test_operation_permission_precedes_nested_company_binding(): void
    {
        $this->actingAsPermissions();

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.invoices.index', ['company' => 1_000_000])),
        );

        $capture['result']->assertForbidden();
        $this->assertSame([], $capture['records']);
    }

    public function test_exact_permission_reads_invoice_without_company_or_payment_permissions(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-EXACT');
        $this->payment($invoice, 'confirmed', 'API-INVOICE-EXACT-PAYMENT');
        $user = $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->getJson(route('api.invoices.show', $invoice))
            ->assertOk()
            ->assertJsonPath('paid_amount', '25.00')
            ->assertJsonMissingPath('payments');

        $this->assertFalse($user->can(PermissionName::CompaniesView->value));
        $this->assertFalse($user->can(PermissionName::PaymentsView->value));
    }

    public function test_custom_role_works_independently_of_role_name(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-CUSTOM-ROLE');
        $user = $this->actingAsCustomRole([PermissionName::InvoicesView->value]);

        $this->getJson(route('api.companies.invoices.index', $invoice->company_id))->assertOk();

        $this->assertFalse($user->hasRole('administrator'));
        $this->assertFalse($user->can(PermissionName::CompaniesView->value));
        $this->assertFalse($user->can(PermissionName::PaymentsView->value));
    }

    public function test_administrator_uses_central_gate_before_for_invoice_access(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-ADMIN');
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user, 'web');

        $this->getJson(route('api.invoices.show', $invoice))->assertOk();
    }

    public function test_index_and_show_invoke_expected_invoice_policy_abilities(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-POLICY');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === Invoice::class) {
                $abilities[] = ['viewAny', Invoice::class];
            }

            if ($ability === 'view' && ($arguments[0] ?? null) instanceof Invoice) {
                $abilities[] = ['view', $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->getJson(route('api.companies.invoices.index', $invoice->company_id))->assertOk();
        $this->getJson(route('api.invoices.show', $invoice))->assertOk();

        $this->assertContains(['viewAny', Invoice::class], $abilities);
        $this->assertContains(['view', $invoice->id], $abilities);
    }

    public function test_policy_denial_after_binding_runs_no_relation_or_financial_queries(): void
    {
        $invoice = $this->invoice(number: 'API-INVOICE-POLICY-DENIED');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'view' ? false : null);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.show', $invoice)),
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
    }

    public function test_show_uses_the_intended_bound_invoice(): void
    {
        $shown = $this->invoice(number: 'API-INVOICE-BOUND-SHOWN');
        $other = $this->invoice(number: 'API-INVOICE-BOUND-OTHER');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->getJson(route('api.invoices.show', $shown))
            ->assertOk()
            ->assertJsonPath('id', $shown->id)
            ->assertJsonPath('invoice_number', $shown->invoice_number)
            ->assertJsonMissing(['invoice_number' => $other->invoice_number]);
    }

    public function test_missing_invoice_is_not_found_after_exact_permission(): void
    {
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->getJson(route('api.invoices.show', ['invoice' => 1_000_000]))->assertNotFound();
    }

    public function test_nested_index_is_scoped_to_the_bound_company(): void
    {
        $company = $this->company('API-INVOICE-BOUND-COMPANY');
        $otherCompany = $this->company('API-INVOICE-OTHER-COMPANY');
        $invoice = $this->invoiceForCompany($company, 'API-INVOICE-BOUND');
        $other = $this->invoiceForCompany($otherCompany, 'API-INVOICE-OTHER');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $response = $this->getJson(route('api.companies.invoices.index', $company))->assertOk();

        $this->assertSame([$invoice->id], array_column($response->json(), 'id'));
        $response->assertDontSee($other->invoice_number);
    }

    public function test_store_guest_is_rejected_without_mutation(): void
    {
        $company = $this->company('API-INVOICE-STORE-STATE');
        $contract = $this->contract($company);
        $payload = $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-STATE');

        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertUnauthorized();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_inactive_user_is_rejected_without_mutation(): void
    {
        $company = $this->company('API-INVOICE-STORE-INACTIVE');
        $contract = $this->contract($company);
        $payload = $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-INACTIVE');

        $inactive = User::factory()->inactive()->create();
        $inactive->givePermissionTo(PermissionName::InvoicesCreate->value);
        $this->actingAs($inactive, 'web');
        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_password_change_user_is_rejected_without_mutation(): void
    {
        $company = $this->company('API-INVOICE-STORE-PASSWORD');
        $contract = $this->contract($company);
        $payload = $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-PASSWORD');
        $passwordChange = User::factory()->requiringPasswordChange()->create();
        $passwordChange->givePermissionTo(PermissionName::InvoicesCreate->value);
        $this->actingAs($passwordChange, 'web');
        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_missing_and_wrong_permissions_precede_existing_or_missing_company_binding(): void
    {
        $company = $this->company('API-INVOICE-STORE-DENIED');
        $contract = $this->contract($company);
        $payload = $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-DENIED');

        foreach ([[], [PermissionName::InvoicesUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->postJson(route('api.companies.invoices.store', $company), $payload),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->postJson(
                    route('api.companies.invoices.store', ['company' => 1_000_000]),
                    $payload
                ),
            );

            $existing['result']->assertForbidden();
            $missing['result']->assertForbidden();
            $this->assertSame($existing['result']->json('message'), $missing['result']->json('message'));
            $this->assertSame([], $existing['records']);
            $this->assertSame([], $missing['records']);
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_exact_create_permission_has_no_hidden_sibling_permissions(): void
    {
        $company = $this->company('API-INVOICE-STORE-EXACT');
        $contract = $this->contract($company);
        $user = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-EXACT')
        )->assertCreated();

        $this->assertFalse($user->can(PermissionName::CompaniesView->value));
        $this->assertFalse($user->can(PermissionName::ContractsView->value));
        $this->assertFalse($user->can(PermissionName::PaymentsView->value));
    }

    public function test_custom_role_and_administrator_can_create_invoices(): void
    {
        $company = $this->company('API-INVOICE-STORE-ROLES');
        $contract = $this->contract($company);
        $custom = $this->actingAsCustomRole([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-CUSTOM')
        )->assertCreated();
        $this->assertFalse($custom->hasRole('administrator'));

        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');
        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-ADMIN')
        )->assertCreated();
    }

    public function test_create_policy_runs_after_company_binding_and_before_validation_or_domain_queries(): void
    {
        $company = $this->company('API-INVOICE-STORE-POLICY');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === Invoice::class) {
                $abilities[] = $ability;
            }
        });

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->invoiceCreationPayload($contract, 'API-INVOICE-STORE-POLICY')
        )->assertCreated();
        $this->assertContains('create', $abilities);

        Gate::before(fn ($user, string $ability): ?bool => $ability === 'create' ? false : null);
        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(route('api.companies.invoices.store', $company), []),
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['companies'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 1);
    }

    private function invoiceForCompany(Company $company, string $number): Invoice
    {
        $contract = $this->contract($company);

        return $company->invoices()->create([
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2099-08-31',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function invoiceCreationPayload(Contract $contract, string $number): array
    {
        return [
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '999.99',
            'lines' => [[
                'description' => 'Authorization manual line',
                'amount' => '100.00',
            ]],
        ];
    }
}
