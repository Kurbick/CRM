<?php

namespace Tests\Feature\Authorization;

use App\Models\Contract;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainQueryRecorder;

class ApiContractAuthorizationTest extends AuthorizationTestCase
{
    public function test_guest_is_stopped_before_contract_access(): void
    {
        $contract = $this->contract($this->company('CONTRACT-GUEST'));

        $this->getJson(route('api.contracts.show', $contract))->assertUnauthorized();
    }

    public function test_inactive_user_is_stopped_before_contract_access(): void
    {
        $contract = $this->contract($this->company('CONTRACT-INACTIVE'));
        $user = User::factory()->inactive()->create();
        $user->givePermissionTo(PermissionName::ContractsView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.contracts.show', $contract))
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }

    public function test_password_change_user_is_stopped_before_contract_access(): void
    {
        $contract = $this->contract($this->company('CONTRACT-PASSWORD'));
        $user = User::factory()->requiringPasswordChange()->create();
        $user->givePermissionTo(PermissionName::ContractsView->value);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.contracts.show', $contract))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_missing_and_wrong_permissions_fail_before_contract_binding_or_queries(): void
    {
        $contract = $this->contract($this->company('CONTRACT-DENIED'));

        foreach ([[], [PermissionName::ContractsUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.contracts.show', $contract)),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.contracts.show', [
                    'contract' => $contract->id + 1_000_000,
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

    public function test_exact_permission_and_custom_role_work_without_companies_view(): void
    {
        $company = $this->company('CONTRACT-ROLE-COMPANY');
        $contract = $this->contract($company);

        $exactUser = $this->actingAsPermissions([PermissionName::ContractsView->value]);
        $this->getJson(route('api.contracts.show', $contract))->assertOk();
        $this->assertFalse($exactUser->can(PermissionName::CompaniesView->value));

        $customUser = $this->actingAsCustomRole([PermissionName::ContractsView->value]);
        $this->getJson(route('api.companies.contracts.index', $company))->assertOk();
        $this->assertFalse($customUser->hasRole('administrator'));
        $this->assertFalse($customUser->can(PermissionName::CompaniesView->value));
    }

    public function test_administrator_uses_central_gate_before_for_contract_access(): void
    {
        $contract = $this->contract($this->company('CONTRACT-ADMIN'));
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user, 'web');

        $this->getJson(route('api.contracts.show', $contract))->assertOk();
    }

    public function test_index_invokes_view_any_policy_after_company_binding(): void
    {
        $company = $this->company('CONTRACT-INDEX-POLICY');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === Contract::class) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->getJson(route('api.companies.contracts.index', $company))->assertOk();

        $this->assertSame(['viewAny'], $abilities);
    }

    public function test_store_policy_runs_before_validation(): void
    {
        $company = $this->company('CONTRACT-STORE-POLICY');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === Contract::class) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->postJson(route('api.companies.contracts.store', $company), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract_number', 'start_date']);

        $this->assertSame(['create'], $abilities);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_update_policy_runs_before_validation_for_bound_contract(): void
    {
        $contract = $this->contract($this->company('CONTRACT-UPDATE-POLICY'));
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'update' && ($arguments[0] ?? null) instanceof Contract) {
                $abilities[] = [
                    'ability' => $ability,
                    'id' => $arguments[0]->id,
                ];
            }
        });
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $this->patchJson(route('api.contracts.update', $contract), [
            'signed_document' => 'forbidden.pdf',
        ])->assertUnprocessable()->assertJsonValidationErrors('signed_document');

        $this->assertSame([[
            'ability' => 'update',
            'id' => $contract->id,
        ]], $abilities);
    }

    public function test_show_and_destroy_invoke_model_policies_for_intended_contract(): void
    {
        $showContract = $this->contract($this->company('CONTRACT-SHOW-POLICY'));
        $deleteContract = $this->contract($this->company('CONTRACT-DELETE-POLICY'));
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if (in_array($ability, ['view', 'delete'], true)
                && ($arguments[0] ?? null) instanceof Contract) {
                $abilities[] = [$ability, $arguments[0]->id];
            }
        });
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractsDelete->value,
        ]);

        $this->getJson(route('api.contracts.show', $showContract))->assertOk();
        $this->deleteJson(route('api.contracts.destroy', $deleteContract))->assertOk();

        $this->assertContains(['view', $showContract->id], $abilities);
        $this->assertContains(['delete', $deleteContract->id], $abilities);
    }

    #[DataProvider('missingBoundRouteProvider')]
    public function test_missing_id_returns_not_found_after_exact_permission(
        string $method,
        string $routeName,
        string $permission,
        array $payload,
    ): void {
        $this->actingAsPermissions([$permission]);
        $uri = route($routeName, ['contract' => 1_000_000]);

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
            'show' => ['GET', 'api.contracts.show', PermissionName::ContractsView->value, []],
            'update' => ['PATCH', 'api.contracts.update', PermissionName::ContractsUpdate->value, [
                'comment' => 'MISSING-CONTRACT-SHOULD-NOT-EXIST',
            ]],
            'destroy' => ['DELETE', 'api.contracts.destroy', PermissionName::ContractsDelete->value, []],
        ];
    }
}
