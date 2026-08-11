<?php

namespace Tests\Feature\Authorization;

use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentAuthorizationTest extends AuthorizationTestCase
{
    public function test_store_guest_inactive_and_password_restricted_users_are_stopped_before_binding(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-MIDDLEWARE');
        $url = route('api.invoices.payments.store', $invoice);

        $guest = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson($url, $this->storePayload()),
        );
        $guest['result']->assertUnauthorized();
        $this->assertSame([], $guest['records']);

        foreach ([
            User::factory()->inactive()->create(),
            User::factory()->requiringPasswordChange()->create(),
        ] as $user) {
            $user->givePermissionTo(PermissionName::PaymentsCreate->value);
            $this->actingAs($user, 'web');
            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->postJson($url, $this->storePayload()),
            );
            $capture['result']->assertForbidden();
            $this->assertSame([], $capture['records']);
        }
    }

    public function test_store_permission_denial_hides_existing_and_missing_invoices_without_queries_or_validation(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-PRE-BINDING');

        foreach ([[], [PermissionName::PaymentsView->value]] as $permissions) {
            $this->actingAsPermissions($permissions);
            $captures = [];

            foreach ([$invoice->id, 1_000_000] as $invoiceId) {
                $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
                    route('api.invoices.payments.store', ['invoice' => $invoiceId]),
                    ['amount' => 'invalid'],
                ));
                $captures[] = $capture;

                $capture['result']->assertForbidden();
                $this->assertSame([], $capture['records']);
            }

            $this->assertSame(
                $captures[0]['result']->json(),
                $captures[1]['result']->json(),
            );
        }
    }

    public function test_store_policy_denial_receives_bound_invoice_before_validation_and_stops_financial_queries(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-POLICY');
        $actualInvoiceId = null;
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);
        Gate::before(function ($user, string $ability, array $arguments) use (&$actualInvoiceId): ?bool {
            if ($ability === 'create' && ($arguments[0] ?? null) === Payment::class) {
                $actualInvoiceId = ($arguments[1] ?? null)?->id;

                return false;
            }

            return null;
        });

        $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
            route('api.invoices.payments.store', $invoice),
            ['amount' => 'invalid'],
        ));

        $capture['result']->assertForbidden();
        $this->assertSame($invoice->id, $actualInvoiceId);
        $this->assertSame(['invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
    }

    public function test_store_standard_policy_receives_the_actual_bound_invoice(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-BOUND-POLICY');
        $actualInvoiceId = null;
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$actualInvoiceId): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === Payment::class) {
                $actualInvoiceId = ($arguments[1] ?? null)?->id;
            }
        });
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        $this->postJson(route('api.invoices.payments.store', $invoice), $this->storePayload())
            ->assertCreated();

        $this->assertSame($invoice->id, $actualInvoiceId);
    }

    public function test_exact_create_permission_and_custom_role_store_without_invoice_or_company_permissions(): void
    {
        foreach (['permission', 'custom-role'] as $mode) {
            $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-'.strtoupper($mode));
            $user = $mode === 'permission'
                ? $this->actingAsPermissions([PermissionName::PaymentsCreate->value])
                : $this->actingAsCustomRole([PermissionName::PaymentsCreate->value]);

            $this->postJson(route('api.invoices.payments.store', $invoice), $this->storePayload())
                ->assertCreated()
                ->assertJsonPath('status', 'pending');

            $this->assertFalse($user->can(PermissionName::InvoicesView->value));
            $this->assertFalse($user->can(PermissionName::InvoicesUpdate->value));
            $this->assertFalse($user->can(PermissionName::CompaniesView->value));
            if ($mode === 'custom-role') {
                $this->assertFalse($user->hasRole('administrator'));
            }
        }
    }

    public function test_store_authorized_missing_invoice_is_not_found(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        $this->postJson(
            route('api.invoices.payments.store', ['invoice' => 1_000_000]),
            $this->storePayload(),
        )->assertNotFound();
    }

    public function test_administrator_uses_centralized_bypass_for_store_authorization(): void
    {
        $invoice = $this->invoice('issued', 'API-PAYMENT-STORE-ADMIN');
        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');

        $this->postJson(route('api.invoices.payments.store', $invoice), $this->storePayload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending');
    }

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
            ->assertJsonPath('data.0.id', $payment->id);
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

    /** @return array<string, string> */
    private function storePayload(): array
    {
        return [
            'payment_date' => '2026-08-05',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'comment' => 'API authorization store',
        ];
    }
}
