<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Policies\CompanyContactPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;

class PolicyDiscoveryTest extends AuthorizationTestCase
{
    public function test_financial_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(InvoicePolicy::class, Gate::getPolicyFor(Invoice::class));
        $this->assertInstanceOf(PaymentPolicy::class, Gate::getPolicyFor(Payment::class));
        $this->assertInstanceOf(CompanyPolicy::class, Gate::getPolicyFor(Company::class));
        $this->assertInstanceOf(CompanyContactPolicy::class, Gate::getPolicyFor(CompanyContact::class));
    }

    public function test_payment_create_policy_receives_invoice_context_and_uses_only_permission(): void
    {
        $invoice = $this->invoice('issued');
        $withoutPermission = User::factory()->create();

        $this->assertFalse(
            Gate::forUser($withoutPermission)->allows('create', [Payment::class, $invoice])
        );

        $customRoleUser = $this->actingAsCustomRole([
            PermissionName::PaymentsCreate->value,
        ]);

        $this->assertTrue(
            Gate::forUser($customRoleUser)->allows('create', [Payment::class, $invoice])
        );
        $this->assertFalse($customRoleUser->hasRole('accountant'));
        $this->assertFalse($customRoleUser->hasRole('administrator'));
    }

    public function test_company_contact_create_policy_receives_company_context_and_uses_only_permission(): void
    {
        $company = $this->company('Contact policy company');
        $withoutPermission = User::factory()->create();

        $this->assertFalse(
            Gate::forUser($withoutPermission)->allows(
                'create',
                [CompanyContact::class, $company]
            )
        );

        $customRoleUser = $this->actingAsCustomRole([
            PermissionName::CompanyContactsCreate->value,
        ]);

        $this->assertTrue(
            Gate::forUser($customRoleUser)->allows(
                'create',
                [CompanyContact::class, $company]
            )
        );
        $this->assertFalse($customRoleUser->can(PermissionName::CompaniesView->value));
        $this->assertFalse($customRoleUser->hasRole('administrator'));
    }
}
