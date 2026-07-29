<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\CompanyContactPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContractDocumentPolicy;
use App\Policies\ContractPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\SubscriptionPolicy;
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
        $this->assertInstanceOf(ContractPolicy::class, Gate::getPolicyFor(Contract::class));
        $this->assertInstanceOf(ContractDocumentPolicy::class, Gate::getPolicyFor(ContractDocument::class));
        $this->assertInstanceOf(OrderPolicy::class, Gate::getPolicyFor(Order::class));
        $this->assertInstanceOf(SubscriptionPolicy::class, Gate::getPolicyFor(Subscription::class));
    }

    public function test_subject_policies_use_only_the_matching_common_permission(): void
    {
        $contract = $this->contract($this->company('Subject policy company'));
        $order = $this->subjectOrder($contract);
        $subscription = $this->subjectSubscription($contract);
        $abilities = [
            ['create', [Order::class, $contract], PermissionName::ContractSubjectsCreate],
            ['create', [Subscription::class, $contract], PermissionName::ContractSubjectsCreate],
            ['update', $order, PermissionName::ContractSubjectsUpdate],
            ['update', $subscription, PermissionName::ContractSubjectsUpdate],
            ['delete', $order, PermissionName::ContractSubjectsDelete],
            ['delete', $subscription, PermissionName::ContractSubjectsDelete],
        ];

        foreach ($abilities as [$ability, $target, $permission]) {
            $user = $this->actingAsCustomRole([$permission->value]);
            $this->assertTrue(Gate::forUser($user)->allows($ability, $target));
            $this->assertFalse($user->hasRole('administrator'));
        }
    }

    public function test_contract_document_policy_uses_one_exact_permission_per_ability(): void
    {
        $contract = $this->contract($this->company('Document policy company'));
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'policy.pdf',
            'file_path' => "contract-documents/{$contract->id}/policy.pdf",
        ]);
        $abilities = [
            ['view', $document, PermissionName::ContractsView],
            ['create', [ContractDocument::class, $contract], PermissionName::ContractDocumentsUpload],
            ['download', $document, PermissionName::ContractDocumentsDownload],
            ['delete', $document, PermissionName::ContractDocumentsDelete],
        ];

        foreach ($abilities as [$ability, $target, $permission]) {
            $withoutPermission = User::factory()->create();
            $exactUser = $this->actingAsCustomRole([$permission->value]);

            $this->assertFalse(Gate::forUser($withoutPermission)->allows($ability, $target));
            $this->assertTrue(Gate::forUser($exactUser)->allows($ability, $target));
            $this->assertFalse($exactUser->hasRole('administrator'));
        }
    }

    public function test_fresh_system_roles_follow_registered_contract_document_defaults(): void
    {
        $administrator = Role::findByName('administrator');
        $accountant = Role::findByName('accountant');
        $viewer = Role::findByName('viewer');

        foreach ([
            PermissionName::ContractDocumentsUpload,
            PermissionName::ContractDocumentsDownload,
            PermissionName::ContractDocumentsDelete,
        ] as $permission) {
            $this->assertTrue($administrator->hasPermissionTo($permission->value));
        }

        $this->assertTrue($accountant->hasPermissionTo(PermissionName::ContractDocumentsDownload->value));
        $this->assertFalse($accountant->hasPermissionTo(PermissionName::ContractDocumentsUpload->value));
        $this->assertFalse($accountant->hasPermissionTo(PermissionName::ContractDocumentsDelete->value));
        $this->assertTrue($viewer->hasPermissionTo(PermissionName::ContractDocumentsDownload->value));
        $this->assertFalse($viewer->hasPermissionTo(PermissionName::ContractDocumentsUpload->value));
        $this->assertFalse($viewer->hasPermissionTo(PermissionName::ContractDocumentsDelete->value));
    }

    public function test_fresh_system_roles_follow_registry_subject_defaults(): void
    {
        $administrator = Role::findByName('administrator');
        $accountant = Role::findByName('accountant');
        $viewer = Role::findByName('viewer');

        foreach (PermissionName::cases() as $permission) {
            if (! str_starts_with($permission->value, 'contract_subjects.')) {
                continue;
            }
            $this->assertTrue($administrator->hasPermissionTo($permission->value));
            $this->assertFalse($accountant->hasPermissionTo($permission->value));
            $this->assertFalse($viewer->hasPermissionTo($permission->value));
        }
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
