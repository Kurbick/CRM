<?php

namespace Tests\Feature\Authorization;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Access\PermissionName;

class SubscriptionAuthorizationTest extends AuthorizationTestCase
{
    public function test_create_requires_exact_permission_and_forbidden_store_has_no_side_effects(): void
    {
        $contract = $this->contract($this->company());
        $payload = $this->subscriptionPayload('FORBIDDEN-SUBSCRIPTION');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->get(route('contracts.subscriptions.create', $contract))->assertForbidden();
        $this->post(route('contracts.subscriptions.store', $contract), $payload)->assertForbidden();
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('service_types', 0);
    }

    public function test_create_only_user_uses_parent_ignores_protected_fields_and_redirects_safely(): void
    {
        $contract = $this->contract($this->company('Subscription A'));
        $other = $this->contract($this->company('Subscription B'));
        $wrongType = $this->subjectServiceType('one_time');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->get(route('contracts.subscriptions.create', $contract))
            ->assertOk()
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $contract->company).'"', false);

        $this->post(route('contracts.subscriptions.store', $contract), [
            ...$this->subscriptionPayload('PARENT-SAFE-SUBSCRIPTION'),
            'contract_id' => $other->id,
            'company_id' => $other->company_id,
            'service_type_id' => $wrongType->id,
            'next_billing_date' => '2035-01-01',
            'unknown' => 'ignored',
        ])->assertRedirect(route('dashboard'));

        $subscription = Subscription::query()->whereHas('serviceType', fn ($query) => $query->where('name', 'PARENT-SAFE-SUBSCRIPTION'))->firstOrFail();
        $this->assertSame($contract->id, $subscription->contract_id);
        $this->assertSame('subscription', $subscription->serviceType->type);
        $this->assertNotSame($wrongType->id, $subscription->service_type_id);
        $this->assertSame('2026-08-10', $subscription->next_billing_date);
    }

    public function test_forbidden_update_preserves_all_fields_schedule_and_invoice_due_date(): void
    {
        $contract = $this->contract($this->company());
        $subscription = $this->subjectSubscription($contract);
        $invoice = $this->invoiceForSubscription($subscription);
        $original = $subscription->fresh()->getAttributes();
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);

        $this->get(route('subscriptions.edit', $subscription))->assertForbidden();
        $this->put(route('subscriptions.update', $subscription), $this->updatePayload($contract))->assertForbidden();

        $this->assertSame($original, $subscription->fresh()->getAttributes());
        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
    }

    public function test_update_only_user_cannot_reparent_or_set_next_billing_date_and_redirects_safely(): void
    {
        $contract = $this->contract($this->company('Subscription update A'));
        $other = $this->contract($this->company('Subscription update B'));
        $subscription = $this->subjectSubscription($contract);
        $maliciousType = $this->subjectServiceType('one_time');
        $originalCreatedAt = $subscription->created_at;
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false);
        $this->put(route('subscriptions.update', $subscription), [
            ...$this->updatePayload($other),
            'service_type_id' => $maliciousType->id,
            'next_billing_date' => '2035-01-01',
            'created_at' => '1999-01-01 00:00:00',
            'updated_at' => '1999-01-02 00:00:00',
            'unknown_subscription_marker' => 'MALICIOUS-SUBSCRIPTION-UPDATE',
        ])->assertRedirect(route('dashboard'));

        $subscription->refresh();
        $this->assertSame($contract->id, $subscription->contract_id);
        $this->assertSame('2026-09-01', $subscription->next_billing_date);
        $this->assertNull($subscription->service_type_id);
        $this->assertNotSame($maliciousType->id, $subscription->service_type_id);
        $this->assertTrue($originalCreatedAt->equalTo($subscription->created_at));
        $this->assertNotSame('1999-01-02 00:00:00', $subscription->updated_at?->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('unknown_subscription_marker', $subscription->getAttributes());
    }

    public function test_delete_permission_business_rule_ui_and_administrator_behavior(): void
    {
        $contract = $this->contract($this->company());
        $unused = $this->subjectSubscription($contract, ['title' => 'Unused subscription']);
        $used = $this->subjectSubscription($contract, ['title' => 'Used subscription']);
        $chain = $this->subjectFinancialChain($used);

        $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $this->get(route('contracts.show', $contract))
            ->assertDontSee('action="'.route('subscriptions.destroy', $unused).'"', false)
            ->assertDontSee(route('subscriptions.destroy', $unused), false);

        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsUpdate->value,
            PermissionName::ContractSubjectsDelete->value,
        ]);

        $this->get(route('contracts.show', $contract))
            ->assertSee(route('subscriptions.edit', $unused), false)
            ->assertSee('action="'.route('subscriptions.destroy', $unused).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('action="'.route('subscriptions.destroy', $used).'"', false);

        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        $this->delete(route('subscriptions.destroy', $unused))->assertRedirect(route('dashboard'));
        $this->delete(route('subscriptions.destroy', $used))
            ->assertSessionHas('error', 'Невозможно удалить подписку, поскольку она уже используется в инвойсе.');
        $this->assertDatabaseMissing('subscriptions', ['id' => $unused->id]);
        $this->assertSubjectFinancialChainExists($used, $chain);

        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator);
        $this->delete(route('subscriptions.destroy', $used))->assertSessionHas('error');
        $this->assertSubjectFinancialChainExists($used, $chain);
    }

    public function test_create_update_and_delete_redirect_to_contract_with_only_contract_view(): void
    {
        $this->assertMutationRedirects(PermissionName::ContractsView, 'contract');
    }

    public function test_create_update_and_delete_redirect_to_company_with_only_company_view(): void
    {
        $this->assertMutationRedirects(PermissionName::CompaniesView, 'company');
    }

    public function test_typed_create_and_edit_disclose_only_minimal_subscription_context(): void
    {
        ['contract' => $contract, 'markers' => $markers] = $this->subjectDisclosureContext('SUBSEC');
        $subscription = $this->subjectSubscription($contract, ['title' => 'VISIBLE-EDITED-SUBSCRIPTION']);

        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);
        $create = $this->get(route('contracts.subscriptions.create', $contract))
            ->assertOk()
            ->assertSee($contract->contract_number)
            ->assertSee($contract->company->name)
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $contract->company).'"', false)
            ->assertDontSee('name="contract_id"', false)
            ->assertDontSee('name="company_id"', false);
        foreach ($markers as $marker) {
            $create->assertDontSee($marker);
        }

        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);
        $edit = $this->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertSee($subscription->title)
            ->assertSee($contract->contract_number)
            ->assertSee($contract->company->name)
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $contract->company).'"', false)
            ->assertDontSee('name="contract_id"', false)
            ->assertDontSee('name="company_id"', false);
        foreach ($markers as $marker) {
            $edit->assertDontSee($marker);
        }
    }

    private function assertMutationRedirects(PermissionName $viewPermission, string $destination): void
    {
        $contract = $this->contract($this->company('Subscription redirect '.uniqid()));
        $expected = $destination === 'contract'
            ? route('contracts.show', $contract)
            : route('companies.show', $contract->company);

        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsCreate->value]);
        $this->post(route('contracts.subscriptions.store', $contract), $this->subscriptionPayload('SUB-REDIRECT-'.uniqid()))
            ->assertRedirect($expected);

        $subscription = $this->subjectSubscription($contract);
        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsUpdate->value]);
        $this->put(route('subscriptions.update', $subscription), $this->updatePayload($contract))
            ->assertRedirect($expected);

        $unused = $this->subjectSubscription($contract);
        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsDelete->value]);
        $this->delete(route('subscriptions.destroy', $unused))->assertRedirect($expected);
        $this->assertDatabaseMissing('subscriptions', ['id' => $unused->id]);
    }

    private function invoiceForSubscription(Subscription $subscription): Invoice
    {
        $invoice = Invoice::query()->create([
            'company_id' => $subscription->contract->company_id,
            'contract_id' => $subscription->contract_id,
            'invoice_number' => 'SUBSCRIPTION-AUTH-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => $subscription->title,
            'amount' => '100.00',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);

        return $invoice;
    }

    private function updatePayload($otherContract): array
    {
        return [
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'title' => 'Updated subscription',
            'start_date' => '2026-09-01',
            'billing_period' => 'quarterly',
            'amount' => '150.00',
            'payment_terms' => 7,
            'status' => 'suspended',
            'comment' => 'Updated subscription comment',
        ];
    }
}
