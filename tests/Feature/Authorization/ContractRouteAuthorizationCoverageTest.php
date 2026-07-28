<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\ContractController;
use App\Models\Contract;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class ContractRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    /** @var array<string, array{methods: list<string>, controller: class-string, ability: string, target: class-string, permission: string, wrong_permission: string, scenario: string}> */
    private const AUTHORIZATION_MATRIX = [
        'contracts.store' => [
            'methods' => ['POST'],
            'controller' => ContractController::class,
            'ability' => 'create',
            'target' => Contract::class,
            'permission' => PermissionName::ContractsCreate->value,
            'wrong_permission' => PermissionName::ContractsUpdate->value,
            'scenario' => 'store',
        ],
        'contracts.update' => [
            'methods' => ['PUT'],
            'controller' => ContractController::class,
            'ability' => 'update',
            'target' => Contract::class,
            'permission' => PermissionName::ContractsUpdate->value,
            'wrong_permission' => PermissionName::ContractsCreate->value,
            'scenario' => 'update',
        ],
        'contracts.destroy' => [
            'methods' => ['DELETE'],
            'controller' => ContractController::class,
            'ability' => 'delete',
            'target' => Contract::class,
            'permission' => PermissionName::ContractsDelete->value,
            'wrong_permission' => PermissionName::ContractsUpdate->value,
            'scenario' => 'destroy',
        ],
    ];

    public function test_every_contract_mutation_route_is_in_matrix(): void
    {
        $mutationMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->getControllerClass() === ContractController::class
                && array_intersect($mutationMethods, $route->methods()) !== [])
            ->keyBy(fn ($route): string => (string) $route->getName());

        $this->assertSame(
            collect(array_keys(self::AUTHORIZATION_MATRIX))->sort()->values()->all(),
            $routes->keys()->sort()->values()->all()
        );

        foreach (self::AUTHORIZATION_MATRIX as $routeName => $definition) {
            $actualMethods = array_values(array_intersect(
                $mutationMethods,
                $routes->get($routeName)->methods()
            ));
            sort($actualMethods);
            $expectedMethods = $definition['methods'];
            sort($expectedMethods);

            $this->assertSame($expectedMethods, $actualMethods);
            $this->assertSame($definition['controller'], $routes->get($routeName)->getControllerClass());
            $this->assertSame(Contract::class, $definition['target']);
            $this->assertNotSame($definition['permission'], $definition['wrong_permission']);
        }
    }

    #[DataProvider('mutationProvider')]
    public function test_without_permission_gate_and_http_reject_and_preserve_database(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions();
        $target = $this->policyTarget($definition);

        $this->assertFalse(Gate::forUser($user)->allows($definition['ability'], $target));
        $this->assertForbiddenScenario($routeName, $definition['scenario']);
    }

    #[DataProvider('mutationProvider')]
    public function test_wrong_permission_gate_and_http_reject_and_preserve_database(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions([$definition['wrong_permission']]);
        $target = $this->policyTarget($definition);

        $this->assertFalse(Gate::forUser($user)->allows($definition['ability'], $target));
        $this->assertForbiddenScenario($routeName, $definition['scenario']);
    }

    #[DataProvider('mutationProvider')]
    public function test_exact_permission_gate_and_http_allow_mutation(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions([$definition['permission']]);
        $target = $this->policyTarget($definition);

        $this->assertTrue($user->can($definition['permission']));
        $this->assertFalse($user->can($definition['wrong_permission']));
        $this->assertTrue(Gate::forUser($user)->allows($definition['ability'], $target));
        $this->assertAllowedScenario($routeName, $definition['scenario']);
    }

    public static function mutationProvider(): array
    {
        return collect(self::AUTHORIZATION_MATRIX)
            ->mapWithKeys(fn (array $definition, string $routeName): array => [
                $routeName => [$routeName, $definition],
            ])
            ->all();
    }

    private function policyTarget(array $definition): mixed
    {
        $this->assertSame(Contract::class, $definition['target']);

        return $definition['ability'] === 'create'
            ? $definition['target']
            : $this->contract($this->company('Matrix target '.uniqid()));
    }

    private function assertForbiddenScenario(string $routeName, string $scenario): void
    {
        match ($scenario) {
            'store' => $this->storeForbidden($routeName),
            'update' => $this->updateForbidden($routeName),
            'destroy' => $this->destroyForbidden($routeName),
        };
    }

    private function assertAllowedScenario(string $routeName, string $scenario): void
    {
        match ($scenario) {
            'store' => $this->storeAllowed($routeName),
            'update' => $this->updateAllowed($routeName),
            'destroy' => $this->destroyAllowed($routeName),
        };
    }

    private function storeForbidden(string $routeName): void
    {
        $company = $this->company('Forbidden store');
        $payload = $this->contractPayload($company, 'FORBIDDEN-STORE');
        $count = DB::table('contracts')->count();

        $this->post(route($routeName), $payload)->assertForbidden();

        $this->assertSame($count, DB::table('contracts')->count());
        $this->assertDatabaseMissing('contracts', ['contract_number' => $payload['contract_number']]);
    }

    private function updateForbidden(string $routeName): void
    {
        $company = $this->company('Forbidden update');
        $otherCompany = $this->company('Forbidden update other');
        $contract = $this->contract($company);
        $original = $contract->only([
            'company_id', 'contract_number', 'start_date', 'end_date', 'status', 'comment',
        ]);
        $payload = $this->updatePayload($contract, $otherCompany->id);

        $this->put(route($routeName, $contract), $payload)->assertForbidden();

        $fresh = $contract->fresh();
        $this->assertSame($original['company_id'], $fresh->company_id);
        $this->assertSame($original['contract_number'], $fresh->contract_number);
        $this->assertEquals($original['start_date'], $fresh->start_date);
        $this->assertEquals($original['end_date'], $fresh->end_date);
        $this->assertSame($original['status'], $fresh->status);
        $this->assertSame($original['comment'], $fresh->comment);
    }

    private function destroyForbidden(string $routeName): void
    {
        $contract = $this->contract($this->company('Forbidden destroy'));

        $this->delete(route($routeName, $contract))->assertForbidden();

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
    }

    private function storeAllowed(string $routeName): void
    {
        $company = $this->company('Allowed store');
        $payload = $this->contractPayload($company, 'ALLOWED-STORE');

        $this->post(route($routeName), $payload)->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('contracts', [
            'company_id' => $company->id,
            'contract_number' => $payload['contract_number'],
        ]);
    }

    private function updateAllowed(string $routeName): void
    {
        $company = $this->company('Allowed update');
        $otherCompany = $this->company('Allowed update other');
        $contract = $this->contract($company);
        $payload = $this->updatePayload($contract, $otherCompany->id);

        $this->put(route($routeName, $contract), $payload)->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'company_id' => $company->id,
            'contract_number' => $payload['contract_number'],
            'status' => 'terminated',
            'comment' => 'Matrix updated comment',
        ]);

        $updatedContract = $contract->fresh();
        $this->assertSame($company->id, $updatedContract->company_id);
        $this->assertSame($payload['contract_number'], $updatedContract->contract_number);
        $this->assertSame('2026-09-01', $updatedContract->start_date->format('Y-m-d'));
        $this->assertSame('2027-09-01', $updatedContract->end_date->format('Y-m-d'));
        $this->assertSame('terminated', $updatedContract->status);
        $this->assertSame('Matrix updated comment', $updatedContract->comment);
    }

    private function destroyAllowed(string $routeName): void
    {
        $contract = $this->contract($this->company('Allowed destroy'));

        $this->delete(route($routeName, $contract))->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
    }

    private function updatePayload(Contract $contract, int $otherCompanyId): array
    {
        return [
            'company_id' => $otherCompanyId,
            'contract_number' => 'UPDATED-'.$contract->id.'-'.uniqid(),
            'start_date' => '2026-09-01',
            'end_date' => '2027-09-01',
            'status' => 'terminated',
            'comment' => 'Matrix updated comment',
        ];
    }
}
