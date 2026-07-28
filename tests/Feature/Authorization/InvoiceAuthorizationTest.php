<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;

class InvoiceAuthorizationTest extends AuthorizationTestCase
{
    public function test_invoice_pages_and_ajax_endpoints_reject_a_user_without_permission(): void
    {
        $invoice = $this->invoice();
        $company = $invoice->company;
        $contract = $invoice->contract;
        $this->actingAsPermissions();

        $this->get(route('invoices.index'))->assertForbidden();
        $this->get(route('invoices.show', $invoice))->assertForbidden();
        $this->get(route('invoices.create'))->assertForbidden();
        $this->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->get(route('ajax.contracts', $company))->assertForbidden();
        $this->get(route('ajax.items', $contract))->assertForbidden();
    }

    public function test_ajax_endpoints_require_create_and_reject_view_or_update_only(): void
    {
        $invoice = $this->invoice();

        foreach ([
            [PermissionName::InvoicesView->value],
            [PermissionName::InvoicesUpdate->value],
        ] as $permissions) {
            $this->actingAsPermissions($permissions);
            $this->get(route('ajax.contracts', $invoice->company))->assertForbidden();
            $this->get(route('ajax.items', $invoice->contract))->assertForbidden();
        }

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $this->get(route('ajax.contracts', $invoice->company))->assertOk();
        $this->get(route('ajax.items', $invoice->contract))->assertOk();
    }

    public function test_invoice_permissions_are_independent_and_custom_roles_work_by_permission(): void
    {
        $invoice = $this->invoice();
        $this->actingAsCustomRole([PermissionName::InvoicesView->value]);

        $this->get(route('invoices.index'))->assertOk();
        $this->get(route('invoices.show', $invoice))->assertOk();
        $this->get(route('invoices.create'))->assertForbidden();
        $this->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->post(route('invoices.issue', $invoice))->assertForbidden();
        $this->delete(route('invoices.destroy', $invoice))->assertForbidden();
    }

    public function test_seeded_viewer_and_accountant_follow_the_actual_permission_matrix(): void
    {
        $invoice = $this->invoice();
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer, 'web');

        $this->get(route('invoices.index'))->assertOk();
        $this->get(route('invoices.show', $invoice))->assertOk();
        $this->get(route('invoices.create'))->assertForbidden();
        $this->get(route('invoices.edit', $invoice))->assertForbidden();

        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $this->actingAs($accountant, 'web');

        $this->get(route('invoices.create'))->assertOk();
        $this->get(route('invoices.edit', $invoice))->assertOk();
        $this->patch(route('invoices.cancel', $invoice))->assertForbidden();
        $this->delete(route('invoices.destroy', $invoice))->assertForbidden();
        $this->assertTrue($accountant->can(PermissionName::InvoicesIssue->value));
        $this->assertFalse($accountant->can(PermissionName::InvoicesCancel->value));
        $this->assertFalse($accountant->can(PermissionName::InvoicesDelete->value));
    }

    public function test_administrator_passes_permissions_but_not_confirmed_payment_editability(): void
    {
        $invoice = $this->invoice('issued');
        $this->payment($invoice, 'confirmed');
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');

        $this->assertTrue(Gate::forUser($administrator)->allows('update', $invoice));

        $this->from(route('invoices.show', $invoice))
            ->put(route('invoices.update', $invoice), $this->invoiceUpdatePayload($invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('100.00', $invoice->fresh()->getRawOriginal('total_amount'));
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_invoice_ui_combines_permissions_with_business_state(): void
    {
        $draft = $this->invoice('draft', 'UI-DRAFT');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->get(route('invoices.show', $draft))
            ->assertOk()
            ->assertDontSee('Редактировать')
            ->assertDontSee('Выставить счёт')
            ->assertDontSee('Удалить')
            ->assertDontSee('Печать');
        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertDontSee(route('invoices.create'), false);

        $allInvoicePermissions = [
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
            PermissionName::InvoicesIssue->value,
            PermissionName::InvoicesCancel->value,
            PermissionName::InvoicesDelete->value,
            PermissionName::InvoicesPrint->value,
        ];
        $this->actingAsPermissions($allInvoicePermissions);

        $this->get(route('invoices.show', $draft))
            ->assertOk()
            ->assertSee('Редактировать')
            ->assertSee('Выставить счёт')
            ->assertSee('Удалить')
            ->assertSee('Печать');

        $paid = $this->invoice('paid', 'UI-PAID');
        $this->get(route('invoices.show', $paid))
            ->assertOk()
            ->assertDontSee('Редактировать')
            ->assertDontSee('Выставить счёт')
            ->assertDontSee('Удалить')
            ->assertDontSee('Отменить счёт')
            ->assertSee('Печать');
    }

    public function test_invoice_navigation_requires_view_permission(): void
    {
        $this->actingAsPermissions();
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('invoices.index'), false);

        $this->actingAsPermissions([PermissionName::InvoicesView->value]);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('invoices.index'), false);
    }
}
