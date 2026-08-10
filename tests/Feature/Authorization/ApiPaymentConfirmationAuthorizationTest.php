<?php

namespace Tests\Feature\Authorization;

use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentConfirmationAuthorizationTest extends AuthorizationTestCase
{
    public function test_guest_inactive_and_password_restricted_users_stop_before_binding(): void
    {
        $payment = $this->payment($this->invoice('issued', 'API-CONFIRM-MIDDLEWARE'));
        $url = route('api.payments.confirm', $payment);

        $guest = (new DomainQueryRecorder)->capture(fn () => $this->postJson($url));
        $guest['result']->assertUnauthorized();
        $this->assertSame([], $guest['records']);

        foreach ([
            User::factory()->inactive()->create(),
            User::factory()->requiringPasswordChange()->create(),
        ] as $user) {
            $user->givePermissionTo(PermissionName::PaymentsConfirm->value);
            $this->actingAs($user, 'web');

            $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson($url));
            $capture['result']->assertForbidden();
            $this->assertSame([], $capture['records']);
        }
    }

    public function test_missing_and_sibling_permissions_hide_existing_and_missing_targets_before_binding(): void
    {
        $payment = $this->payment($this->invoice('issued', 'API-CONFIRM-PRIVACY'));

        foreach ([
            [],
            [PermissionName::PaymentsView->value],
            [PermissionName::PaymentsCreate->value],
        ] as $permissions) {
            $this->actingAsPermissions($permissions);
            $captures = [];

            foreach ([$payment->id, 1_000_000] as $paymentId) {
                $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
                    route('api.payments.confirm', ['payment' => $paymentId]),
                    ['amount' => '99999999.99'],
                ));
                $capture['result']->assertForbidden()->assertJsonMissingValidationErrors();
                $this->assertSame([], $capture['records']);
                $captures[] = $this->withoutDebugTrace($capture['result']->json());
            }

            $this->assertSame($captures[0], $captures[1]);
        }
    }

    public function test_authorized_missing_target_is_not_found(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $this->postJson(route('api.payments.confirm', ['payment' => 1_000_000]))
            ->assertNotFound();
    }

    public function test_policy_receives_bound_payment_before_validation_and_denial_stops_financial_queries(): void
    {
        $payment = $this->payment($this->invoice('issued', 'API-CONFIRM-POLICY'));
        $actualPaymentId = null;
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        Gate::before(function ($user, string $ability, array $arguments) use (&$actualPaymentId): ?bool {
            if ($ability === 'confirm' && ($arguments[0] ?? null) instanceof Payment) {
                $actualPaymentId = $arguments[0]->id;

                return false;
            }

            return null;
        });

        $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
            route('api.payments.confirm', $payment),
            ['unknown' => 'rejected-after-policy'],
        ));

        $capture['result']->assertForbidden()->assertJsonMissingValidationErrors();
        $this->assertSame($payment->id, $actualPaymentId);
        $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_exact_permission_and_custom_role_confirm_without_invoice_or_company_permissions(): void
    {
        foreach (['permission', 'custom-role'] as $mode) {
            $payment = $this->payment($this->invoice('issued', 'API-CONFIRM-'.strtoupper($mode)));
            $user = $mode === 'permission'
                ? $this->actingAsPermissions([PermissionName::PaymentsConfirm->value])
                : $this->actingAsCustomRole([PermissionName::PaymentsConfirm->value]);

            $this->postJson(route('api.payments.confirm', $payment))
                ->assertOk()
                ->assertJsonPath('status', 'confirmed');

            $this->assertFalse($user->can(PermissionName::PaymentsView->value));
            $this->assertFalse($user->can(PermissionName::InvoicesView->value));
            $this->assertFalse($user->can(PermissionName::CompaniesView->value));
            if ($mode === 'custom-role') {
                $this->assertFalse($user->hasRole('administrator'));
            }
        }
    }

    public function test_standard_policy_observes_the_actual_bound_payment(): void
    {
        $payment = $this->payment($this->invoice('issued', 'API-CONFIRM-BOUND-POLICY'));
        $actualPaymentId = null;
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$actualPaymentId): void {
            if ($ability === 'confirm' && ($arguments[0] ?? null) instanceof Payment) {
                $actualPaymentId = $arguments[0]->id;
            }
        });
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $this->postJson(route('api.payments.confirm', $payment))->assertOk();

        $this->assertSame($payment->id, $actualPaymentId);
    }

    public function test_administrator_uses_centralized_bypass_but_not_business_state_bypass(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');

        $confirmed = $this->payment(
            $this->invoice('issued', 'API-CONFIRM-ADMIN-CONFIRMED'),
            'confirmed',
        );
        $this->postJson(route('api.payments.confirm', $confirmed))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $draftPayment = $this->payment($this->invoice('draft', 'API-CONFIRM-ADMIN-DRAFT'));
        $this->postJson(route('api.payments.confirm', $draftPayment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $pending = $this->payment($this->invoice('issued', 'API-CONFIRM-ADMIN-SUCCESS'));
        $this->postJson(route('api.payments.confirm', $pending))
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutDebugTrace(array $payload): array
    {
        unset($payload['trace']);

        return $payload;
    }
}
