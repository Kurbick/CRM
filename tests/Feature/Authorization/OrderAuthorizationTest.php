<?php

namespace Tests\Feature\Authorization;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;

class OrderAuthorizationTest extends AuthorizationTestCase
{
    public function test_create_routes_require_exact_permission_and_forbidden_store_has_no_side_effects(): void
    {
        $contract = $this->contract($this->company());
        $payload = $this->orderPayload('FORBIDDEN-ORDER');
        $this->actingAsPermissions();

        $this->get(route('contracts.orders.create', $contract))->assertForbidden();
        $this->post(route('contracts.orders.store', $contract), $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('service_types', 0);

        foreach ([PermissionName::ContractSubjectsUpdate, PermissionName::ContractSubjectsDelete] as $wrong) {
            $this->actingAsPermissions([$wrong->value]);
            $this->post(route('contracts.orders.store', $contract), $payload)->assertForbidden();
        }
    }

    public function test_create_only_user_uses_route_parent_ignores_extra_fields_and_redirects_safely(): void
    {
        $contract = $this->contract($this->company('Order parent A'));
        $other = $this->contract($this->company('Order parent B'));
        $wrongType = $this->subjectServiceType('subscription');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->get(route('contracts.orders.create', $contract))
            ->assertOk()
            ->assertSee($contract->contract_number)
            ->assertSee($contract->company->name)
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $contract->company).'"', false)
            ->assertSee(route('home'), false);

        $payload = [
            ...$this->orderPayload('PARENT-SAFE-ORDER'),
            'contract_id' => $other->id,
            'company_id' => $other->company_id,
            'service_type_id' => $wrongType->id,
            'created_at' => '2000-01-01 00:00:00',
            'unknown' => 'ignored',
        ];

        $this->post(route('contracts.orders.store', $contract), $payload)
            ->assertRedirect(route('home'));

        $order = Order::query()->whereHas('serviceType', fn ($query) => $query->where('name', 'PARENT-SAFE-ORDER'))->firstOrFail();
        $this->assertSame($contract->id, $order->contract_id);
        $this->assertSame('one_time', $order->serviceType->type);
        $this->assertNotSame($wrongType->id, $order->service_type_id);
        $this->assertNotSame('2000-01-01 00:00:00', $order->created_at?->format('Y-m-d H:i:s'));
    }

    public function test_forbidden_edit_and_update_preserve_order_and_linked_invoice_due_date(): void
    {
        $contract = $this->contract($this->company());
        $order = $this->subjectOrder($contract);
        $invoice = $this->invoiceForOrder($order);
        $original = $order->fresh()->getAttributes();
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->get(route('orders.edit', $order))->assertForbidden();
        $this->put(route('orders.update', $order), $this->orderUpdatePayload($contract))->assertForbidden();

        $this->assertSame($original, $order->fresh()->getAttributes());
        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
    }

    public function test_update_only_user_cannot_reparent_and_redirects_safely(): void
    {
        $contract = $this->contract($this->company('Order update A'));
        $other = $this->contract($this->company('Order update B'));
        $order = $this->subjectOrder($contract);
        $maliciousType = $this->subjectServiceType('subscription');
        $originalCreatedAt = $order->created_at;
        $serviceTypeId = $order->service_type_id;
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $contract->company).'"', false);
        $this->put(route('orders.update', $order), [
            ...$this->orderUpdatePayload($other),
            'service_type_id' => $maliciousType->id,
            'created_at' => '1999-01-01 00:00:00',
            'updated_at' => '1999-01-02 00:00:00',
            'unknown_order_marker' => 'MALICIOUS-ORDER-UPDATE',
        ])
            ->assertRedirect(route('home'));

        $order->refresh();
        $this->assertSame($contract->id, $order->contract_id);
        $this->assertNull($order->service_type_id);
        $this->assertNotSame($serviceTypeId, $order->service_type_id);
        $this->assertNotSame($maliciousType->id, $order->service_type_id);
        $this->assertTrue($originalCreatedAt->equalTo($order->created_at));
        $this->assertNotSame('1999-01-02 00:00:00', $order->updated_at?->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('unknown_order_marker', $order->getAttributes());
        $this->assertSame('Updated order', $order->title);
    }

    public function test_delete_permission_and_business_rule_are_independent_and_ui_has_no_n_plus_one_actions(): void
    {
        $contract = $this->contract($this->company());
        $unused = $this->subjectOrder($contract, ['title' => 'Unused order']);
        $used = $this->subjectOrder($contract, ['title' => 'Used order']);
        $chain = $this->subjectFinancialChain($used);

        $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $this->get(route('contracts.show', $contract))
            ->assertDontSee(route('orders.edit', $unused), false)
            ->assertDontSee('action="'.route('orders.destroy', $unused).'"', false);

        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsUpdate->value,
            PermissionName::ContractSubjectsDelete->value,
        ]);
        $this->get(route('contracts.show', $contract))
            ->assertSee(route('orders.edit', $unused), false)
            ->assertSee('action="'.route('orders.destroy', $unused).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('action="'.route('orders.destroy', $used).'"', false);

        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        $this->delete(route('orders.destroy', $unused))->assertRedirect(route('home'));
        $this->delete(route('orders.destroy', $used))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'Невозможно удалить разовую услугу, поскольку она уже используется в инвойсе.');
        $this->assertDatabaseMissing('orders', ['id' => $unused->id]);
        $this->assertSubjectFinancialChainExists($used, $chain);
    }

    public function test_administrator_cannot_delete_invoiced_order(): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $chain = $this->subjectFinancialChain($order);
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator);

        $this->delete(route('orders.destroy', $order))->assertSessionHas('error');
        $this->assertSubjectFinancialChainExists($order, $chain);
    }

    public function test_create_update_and_delete_redirect_to_contract_with_only_contract_view(): void
    {
        $this->assertMutationRedirects(PermissionName::ContractsView, 'contract');
    }

    public function test_create_update_and_delete_redirect_to_company_with_only_company_view(): void
    {
        $this->assertMutationRedirects(PermissionName::CompaniesView, 'company');
    }

    public function test_typed_create_and_edit_disclose_only_minimal_order_context(): void
    {
        ['contract' => $contract, 'markers' => $markers] = $this->subjectDisclosureContext('ORDSEC');
        $order = $this->subjectOrder($contract, ['title' => 'VISIBLE-EDITED-ORDER']);

        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);
        $create = $this->get(route('contracts.orders.create', $contract))
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
        $edit = $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee($order->title)
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
        $contract = $this->contract($this->company('Order redirect '.uniqid()));
        $expected = $destination === 'contract'
            ? route('contracts.show', $contract)
            : route('companies.show', $contract->company);

        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsCreate->value]);
        $this->post(route('contracts.orders.store', $contract), $this->orderPayload('ORDER-REDIRECT-'.uniqid()))
            ->assertRedirect($expected);

        $order = $this->subjectOrder($contract);
        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsUpdate->value]);
        $this->put(route('orders.update', $order), $this->orderUpdatePayload($contract))
            ->assertRedirect($expected);

        $unused = $this->subjectOrder($contract);
        $this->actingAsPermissions([$viewPermission->value, PermissionName::ContractSubjectsDelete->value]);
        $this->delete(route('orders.destroy', $unused))->assertRedirect($expected);
        $this->assertDatabaseMissing('orders', ['id' => $unused->id]);
    }

    private function invoiceForOrder(Order $order): Invoice
    {
        $invoice = Invoice::query()->create([
            'company_id' => $order->contract->company_id,
            'contract_id' => $order->contract_id,
            'invoice_number' => 'ORDER-AUTH-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
        $invoice->lines()->create(['order_id' => $order->id, 'description' => $order->title, 'amount' => '100.00']);

        return $invoice;
    }

    private function orderUpdatePayload($otherContract): array
    {
        return [
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'title' => 'Updated order',
            'order_date' => '2026-09-01',
            'price' => '150.00',
            'payment_terms' => 7,
            'status' => 'completed',
            'comment' => 'Updated order comment',
        ];
    }
}
