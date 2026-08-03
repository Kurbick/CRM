<?php

namespace Tests\Feature\Authorization;

use App\Models\Contract;
use App\Models\Order;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainQueryRecorder;

class ApiOrderAuthorizationTest extends AuthorizationTestCase
{
    public function test_guest_is_stopped_before_order_access(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-GUEST')));

        $this->getJson(route('api.orders.show', $order))->assertUnauthorized();
    }

    public function test_inactive_user_is_stopped_before_order_access(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-INACTIVE')));
        $user = User::factory()->inactive()->create();
        $user->givePermissionTo(PermissionName::ContractsView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.orders.show', $order))
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }

    public function test_password_change_user_is_stopped_before_order_access(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-PASSWORD')));
        $user = User::factory()->requiringPasswordChange()->create();
        $user->givePermissionTo(PermissionName::ContractsView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.orders.show', $order))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_missing_and_wrong_permissions_fail_before_order_binding_or_queries(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DENIED')));

        foreach ([[], [PermissionName::ContractSubjectsUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.orders.show', $order)),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.orders.show', [
                    'order' => $order->id + 1_000_000,
                ])),
            );

            $existing['result']->assertForbidden();
            $missing['result']->assertForbidden();
            $this->assertSame($existing['result']->status(), $missing['result']->status());
            $this->assertSame($existing['result']->json('message'), $missing['result']->json('message'));
            $this->assertSame([], $existing['records']);
            $this->assertSame([], $missing['records']);
        }
    }

    public function test_exact_permission_and_custom_role_work_without_company_or_invoice_permissions(): void
    {
        $contract = $this->contract($this->company('ORDER-ROLE'));
        $order = $this->subjectOrder($contract);

        $exactUser = $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $this->getJson(route('api.orders.show', $order))->assertOk();
        $this->assertFalse($exactUser->can(PermissionName::CompaniesView->value));
        $this->assertFalse($exactUser->can(PermissionName::InvoicesView->value));

        $customUser = $this->actingAsCustomRole([PermissionName::ContractsView->value]);
        $this->getJson(route('api.contracts.orders.index', $contract))->assertOk();
        $this->assertFalse($customUser->hasRole('administrator'));
        $this->assertFalse($customUser->can(PermissionName::CompaniesView->value));
        $this->assertFalse($customUser->can(PermissionName::InvoicesView->value));
    }

    public function test_administrator_uses_central_gate_before_for_order_access(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-ADMIN')));
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user, 'web');

        $this->getJson(route('api.orders.show', $order))->assertOk();
    }

    public function test_nested_index_authorizes_bound_contract_view(): void
    {
        $contract = $this->contract($this->company('ORDER-INDEX-POLICY'));
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'view' && ($arguments[0] ?? null) instanceof Contract) {
                $abilities[] = [$ability, $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->getJson(route('api.contracts.orders.index', $contract))->assertOk();

        $this->assertSame([['view', $contract->id]], $abilities);
    }

    public function test_store_policy_runs_before_validation_for_bound_contract(): void
    {
        $contract = $this->contract($this->company('ORDER-STORE-POLICY'));
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === Order::class) {
                $abilities[] = [$ability, ($arguments[1] ?? null)?->id];
            }
        });
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->postJson(route('api.contracts.orders.store', $contract), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_type_id', 'order_date', 'price', 'payment_terms']);

        $this->assertSame([['create', $contract->id]], $abilities);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_update_policy_runs_before_validation_for_bound_order(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-UPDATE-POLICY')));
        $subscriptionType = $this->subjectServiceType('subscription');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'update' && ($arguments[0] ?? null) instanceof Order) {
                $abilities[] = [$ability, $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.orders.update', $order), [
            'service_type_id' => $subscriptionType->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('service_type_id');

        $this->assertSame([['update', $order->id]], $abilities);
    }

    public function test_show_and_destroy_invoke_model_policies_for_intended_orders(): void
    {
        $contract = $this->contract($this->company('ORDER-MODEL-POLICY'));
        $shown = $this->subjectOrder($contract);
        $deleted = $this->subjectOrder($contract);
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if (in_array($ability, ['view', 'delete'], true)
                && ($arguments[0] ?? null) instanceof Order) {
                $abilities[] = [$ability, $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsDelete->value,
        ]);

        $this->getJson(route('api.orders.show', $shown))->assertOk();
        $this->deleteJson(route('api.orders.destroy', $deleted))->assertOk();

        $this->assertContains(['view', $shown->id], $abilities);
        $this->assertContains(['delete', $deleted->id], $abilities);
    }

    #[DataProvider('missingBoundRouteProvider')]
    public function test_missing_id_returns_not_found_after_exact_permission(
        string $method,
        string $routeName,
        string $permission,
        array $payload,
    ): void {
        $this->actingAsPermissions([$permission]);
        $uri = route($routeName, ['order' => 1_000_000]);

        $response = match ($method) {
            'GET' => $this->getJson($uri),
            'PATCH' => $this->patchJson($uri, $payload),
            'DELETE' => $this->deleteJson($uri),
        };

        $response->assertNotFound();
    }

    public static function missingBoundRouteProvider(): array
    {
        return [
            'show' => ['GET', 'api.orders.show', PermissionName::ContractsView->value, []],
            'update' => ['PATCH', 'api.orders.update', PermissionName::ContractSubjectsUpdate->value, [
                'comment' => 'MISSING-ORDER-SHOULD-NOT-EXIST',
            ]],
            'destroy' => ['DELETE', 'api.orders.destroy', PermissionName::ContractSubjectsDelete->value, []],
        ];
    }
}
