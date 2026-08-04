<?php

namespace Tests\Feature\Authorization;

use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentAuthorizationTest extends AuthorizationTestCase
{
    public function test_guest_is_stopped_before_payment_binding(): void
    {
        $payment = $this->payment($this->invoice(number: 'API-PAYMENT-GUEST'));

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.payments.show', $payment)),
        );

        $capture['result']->assertUnauthorized();
        $this->assertSame([], $capture['records']);
    }

    public function test_inactive_user_is_stopped_before_payment_binding(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-INACTIVE');
        $user = User::factory()->inactive()->create();
        $user->givePermissionTo(PermissionName::PaymentsView->value);
        $this->actingAs($user, 'web');

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.payments.index', $invoice)),
        );

        $capture['result']
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
        $this->assertSame([], $capture['records']);
    }

    public function test_password_change_requirement_precedes_payment_binding(): void
    {
        $payment = $this->payment($this->invoice(number: 'API-PAYMENT-PASSWORD'));
        $user = User::factory()->requiringPasswordChange()->create();
        $user->givePermissionTo(PermissionName::PaymentsView->value);
        $this->actingAs($user, 'web');

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.payments.show', $payment)),
        );

        $capture['result']
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
        $this->assertSame([], $capture['records']);
    }

    public function test_missing_and_sibling_permissions_hide_existing_or_missing_payment_targets(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-PRE-BINDING');
        $payment = $this->payment($invoice);

        foreach ([[], [PermissionName::PaymentsCreate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            foreach ([
                [
                    route('api.invoices.payments.index', $invoice),
                    route('api.invoices.payments.index', ['invoice' => 1_000_000]),
                ],
                [
                    route('api.payments.show', $payment),
                    route('api.payments.show', ['payment' => 1_000_000]),
                ],
            ] as [$existingUrl, $missingUrl]) {
                $existing = (new DomainQueryRecorder)->capture(
                    fn () => $this->getJson($existingUrl),
                );
                $missing = (new DomainQueryRecorder)->capture(
                    fn () => $this->getJson($missingUrl),
                );

                $existing['result']->assertForbidden();
                $missing['result']->assertForbidden();
                $this->assertSame($existing['result']->json('message'), $missing['result']->json('message'));
                $this->assertSame([], $existing['records']);
                $this->assertSame([], $missing['records']);
            }
        }
    }

    public function test_exact_permission_reads_payments_without_invoice_or_company_permissions(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-EXACT');
        $payment = $this->payment($invoice);
        $user = $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $this->getJson(route('api.invoices.payments.index', $invoice))
            ->assertOk()
            ->assertJsonPath('0.id', $payment->id);
        $this->getJson(route('api.payments.show', $payment))
            ->assertOk()
            ->assertJsonPath('id', $payment->id);

        $this->assertFalse($user->can(PermissionName::InvoicesView->value));
        $this->assertFalse($user->can(PermissionName::CompaniesView->value));
    }

    public function test_custom_role_and_administrator_use_standard_permission_semantics(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-CUSTOM');
        $payment = $this->payment($invoice);
        $custom = $this->actingAsCustomRole([PermissionName::PaymentsView->value]);

        $this->getJson(route('api.payments.show', $payment))->assertOk();
        $this->assertFalse($custom->hasRole('administrator'));

        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');

        $this->getJson(route('api.invoices.payments.index', $invoice))->assertOk();
    }

    public function test_index_and_show_policies_receive_actual_bound_models(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-POLICY');
        $payment = $this->payment($invoice);
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === Payment::class) {
                $abilities[] = ['viewAny', ($arguments[1] ?? null)?->id];
            }

            if ($ability === 'view' && ($arguments[0] ?? null) instanceof Payment) {
                $abilities[] = ['view', $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $this->getJson(route('api.invoices.payments.index', $invoice))->assertOk();
        $this->getJson(route('api.payments.show', $payment))->assertOk();

        $this->assertContains(['viewAny', $invoice->id], $abilities);
        $this->assertContains(['view', $payment->id], $abilities);
    }

    public function test_index_policy_denial_runs_only_invoice_binding_query(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-INDEX-POLICY-DENIED');
        $this->payment($invoice, 'confirmed', 'API-PAYMENT-POLICY-PAYMENT');
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'viewAny' ? false : null);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.payments.index', $invoice)),
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
    }

    public function test_show_policy_denial_runs_only_payment_binding_query(): void
    {
        $invoice = $this->invoice(number: 'API-PAYMENT-SHOW-POLICY-DENIED');
        $payment = $this->payment($invoice, 'confirmed', 'API-PAYMENT-SHOW-POLICY-PAYMENT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);
        Gate::before(fn ($user, string $ability): ?bool => $ability === 'view' ? false : null);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.payments.show', $payment)),
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
    }

    public function test_authorized_missing_invoice_and_payment_are_not_found(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $this->getJson(route('api.invoices.payments.index', ['invoice' => 1_000_000]))
            ->assertNotFound();
        $this->getJson(route('api.payments.show', ['payment' => 1_000_000]))
            ->assertNotFound();
    }
}
