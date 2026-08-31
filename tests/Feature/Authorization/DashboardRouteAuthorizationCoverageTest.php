<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\DashboardController;
use App\Models\User;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DomainQueryRecorder;

class DashboardRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    private const MATRIX = [
        [
            'route' => 'dashboard',
            'uri' => '/',
            'methods' => ['GET'],
            'controller' => DashboardController::class,
            'controller_method' => 'index',
            'middleware' => ['web', 'auth', 'active', 'password.changed', 'organization.context'],
            'ability' => PermissionName::DashboardView->value,
            'exact_permission' => PermissionName::DashboardView->value,
            'wrong_permission' => PermissionName::CompaniesView->value,
            'scenario' => 'index',
            'response_invariant' => 'authorized_shell',
            'query_invariant' => 'no_domain_query_before_gate',
        ],
    ];

    public function test_inventory_contains_every_web_dashboard_controller_route(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getControllerClass() === DashboardController::class)
            ->pluck('action.as')
            ->values()
            ->all();

        $this->assertSame(collect(self::MATRIX)->pluck('route')->all(), $routes);
    }

    #[DataProvider('matrixProvider')]
    public function test_route_metadata_is_executed(array $entry): void
    {
        $route = Route::getRoutes()->getByName($entry['route']);

        $this->assertNotNull($route);
        $this->assertSame($entry['route'], $route->getName());
        $this->assertSame($entry['uri'], '/'.ltrim($route->uri(), '/'));
        $this->assertSame($entry['methods'], array_values(array_intersect(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())));
        $this->assertSame($entry['controller'], $route->getControllerClass());
        $this->assertSame($entry['controller_method'], $route->getActionMethod());
        $this->assertSame($entry['middleware'], $route->gatherMiddleware());
    }

    #[DataProvider('matrixProvider')]
    public function test_direct_gate_uses_matrix_ability_and_permissions(array $entry): void
    {
        $none = User::factory()->create();
        $wrong = User::factory()->create();
        $wrong->givePermissionTo($entry['wrong_permission']);
        $exact = User::factory()->create();
        $exact->givePermissionTo($entry['exact_permission']);
        $administrator = User::factory()->create();
        $administrator->assignRole(SystemRole::Administrator->value);

        $this->assertFalse(Gate::forUser($none)->allows($entry['ability']));
        $this->assertFalse(Gate::forUser($wrong)->allows($entry['ability']));
        $this->assertTrue(Gate::forUser($exact)->allows($entry['ability']));
        $this->assertTrue(Gate::forUser($administrator)->allows($entry['ability']));
    }

    #[DataProvider('matrixProvider')]
    public function test_http_scenarios_execute_matrix_invariants(array $entry): void
    {
        $this->dispatchScenario($entry, null)->assertRedirect(route('login'));

        foreach ([[], [$entry['wrong_permission']]] as $permissions) {
            $this->actingAsPermissions($permissions);
            $capture = $this->captureScenario($entry);
            $capture['result']->assertForbidden();
            $this->assertQueryInvariant($entry, $capture['records']);
            auth()->logout();
        }

        $this->actingAsPermissions([$entry['exact_permission']]);
        $this->assertResponseInvariant($entry, $this->captureScenario($entry));

        $administrator = User::factory()->create();
        $administrator->assignRole(SystemRole::Administrator->value);
        $this->actingAs($administrator);
        $this->assertResponseInvariant($entry, $this->captureScenario($entry));
    }

    public static function matrixProvider(): array
    {
        return collect(self::MATRIX)
            ->mapWithKeys(fn (array $entry): array => [$entry['scenario'] => [$entry]])
            ->all();
    }

    private function captureScenario(array $entry): array
    {
        return (new DomainQueryRecorder)->capture(
            fn () => $this->dispatchScenario($entry, auth()->user())
        );
    }

    private function dispatchScenario(array $entry, ?User $user)
    {
        if ($user !== null) {
            $this->actingAs($user);
        }

        return match ($entry['scenario']) {
            'index' => $this->get(route($entry['route'])),
        };
    }

    private function assertResponseInvariant(array $entry, array $capture): void
    {
        match ($entry['response_invariant']) {
            'authorized_shell' => $capture['result']->assertOk(),
        };
    }

    private function assertQueryInvariant(array $entry, array $records): void
    {
        match ($entry['query_invariant']) {
            'no_domain_query_before_gate' => $this->assertSame([], $records),
        };
    }
}
