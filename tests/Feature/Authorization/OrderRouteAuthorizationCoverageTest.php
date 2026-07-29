<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\OrderController;
use App\Models\Order;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class OrderRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    private const MATRIX = [
        ['route' => 'contracts.orders.store', 'methods' => ['POST'], 'controller' => OrderController::class, 'ability' => 'create', 'target' => 'order_create', 'permission' => PermissionName::ContractSubjectsCreate->value, 'wrong_permission' => PermissionName::ContractSubjectsUpdate->value, 'scenario' => 'store'],
        ['route' => 'orders.update', 'methods' => ['PUT'], 'controller' => OrderController::class, 'ability' => 'update', 'target' => 'order_model', 'permission' => PermissionName::ContractSubjectsUpdate->value, 'wrong_permission' => PermissionName::ContractSubjectsCreate->value, 'scenario' => 'update'],
        ['route' => 'orders.destroy', 'methods' => ['DELETE'], 'controller' => OrderController::class, 'ability' => 'delete', 'target' => 'order_model', 'permission' => PermissionName::ContractSubjectsDelete->value, 'wrong_permission' => PermissionName::ContractSubjectsUpdate->value, 'scenario' => 'destroy'],
    ];

    public function test_every_order_mutation_route_is_in_matrix(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getControllerClass() === OrderController::class
                && array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()))
            ->keyBy(fn ($route) => $route->getName());

        $expectedNames = collect(self::MATRIX)->pluck('route')->sort()->values()->all();
        $this->assertSame($expectedNames, $routes->keys()->sort()->values()->all());
        foreach (self::MATRIX as $definition) {
            $route = $routes[$definition['route']];
            $this->assertSame($definition['methods'], array_values(array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())));
            $this->assertSame($definition['controller'], $route->getControllerClass());
        }
    }

    #[DataProvider('provider')]
    public function test_matrix_metadata_drives_direct_gate_checks(array $definition): void
    {
        $target = $this->resolveTarget($definition['target']);
        $withoutPermission = $this->actingAsPermissions();
        $wrongPermission = $this->actingAsPermissions([$definition['wrong_permission']]);
        $exactPermission = $this->actingAsPermissions([$definition['permission']]);

        $this->assertFalse(Gate::forUser($withoutPermission)->allows($definition['ability'], $target));
        $this->assertFalse(Gate::forUser($wrongPermission)->allows($definition['ability'], $target));
        $this->assertTrue(Gate::forUser($exactPermission)->allows($definition['ability'], $target));
        $this->assertFalse($exactPermission->hasRole('administrator'));
        $this->assertCount(1, $exactPermission->permissions);
    }

    #[DataProvider('provider')]
    public function test_http_without_permission_is_forbidden_and_preserves_database(array $definition): void
    {
        $this->actingAsPermissions();
        $this->assertForbiddenHttp($definition);
    }

    #[DataProvider('provider')]
    public function test_http_with_wrong_permission_is_forbidden_and_preserves_database(array $definition): void
    {
        $this->actingAsPermissions([$definition['wrong_permission']]);
        $this->assertForbiddenHttp($definition);
    }

    #[DataProvider('provider')]
    public function test_http_with_exact_permission_performs_real_mutation(array $definition): void
    {
        $user = $this->actingAsPermissions([$definition['permission']]);
        $this->assertFalse($user->hasRole('administrator'));
        $this->assertCount(1, $user->permissions);
        $this->assertAllowedHttp($definition);
    }

    public static function provider(): array
    {
        return collect(self::MATRIX)->mapWithKeys(fn ($definition) => [$definition['route'] => [$definition]])->all();
    }

    private function resolveTarget(string $target): mixed
    {
        $contract = $this->contract($this->company('Order matrix target '.uniqid()));

        return match ($target) {
            'order_create' => [Order::class, $contract],
            'order_model' => $this->subjectOrder($contract),
            default => $this->fail("Unknown Order Gate target [{$target}]."),
        };
    }

    private function assertForbiddenHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Order forbidden '.uniqid()));
        if ($definition['scenario'] === 'store') {
            $orders = DB::table('orders')->count();
            $types = DB::table('service_types')->count();
            $marker = 'FORBIDDEN-MATRIX-'.uniqid();
            $this->post(route($definition['route'], $contract), $this->orderPayload($marker))->assertForbidden();
            $this->assertSame($orders, DB::table('orders')->count());
            $this->assertSame($types, DB::table('service_types')->count());
            $this->assertDatabaseMissing('service_types', ['name' => $marker]);

            return;
        }

        $order = $this->subjectOrder($contract);
        if ($definition['scenario'] === 'update') {
            $original = $order->fresh()->getAttributes();
            $invoice = $contract->invoices()->create([
                'company_id' => $contract->company_id, 'invoice_number' => 'MATRIX-'.uniqid(),
                'issue_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => 100, 'status' => 'issued',
            ]);
            $invoice->lines()->create(['order_id' => $order->id, 'description' => 'Order', 'amount' => 100]);
            $this->put(route($definition['route'], $order), $this->updatePayload())->assertForbidden();
            $this->assertSame($original, $order->fresh()->getAttributes());
            $this->assertSame('2026-08-31', $invoice->fresh()->due_date);

            return;
        }

        $chain = $this->subjectFinancialChain($order);
        $this->delete(route($definition['route'], $order))->assertForbidden();
        $this->assertSubjectFinancialChainExists($order, $chain);
    }

    private function assertAllowedHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Order allowed '.uniqid()));
        if ($definition['scenario'] === 'store') {
            $marker = 'ALLOWED-MATRIX-'.uniqid();
            $this->post(route($definition['route'], $contract), $this->orderPayload($marker))->assertRedirect(route('dashboard'));
            $this->assertDatabaseHas('orders', ['contract_id' => $contract->id]);
            $this->assertDatabaseHas('service_types', ['name' => $marker, 'type' => 'one_time']);

            return;
        }
        $order = $this->subjectOrder($contract);
        if ($definition['scenario'] === 'update') {
            $this->put(route($definition['route'], $order), $this->updatePayload())->assertRedirect(route('dashboard'));
            $this->assertDatabaseHas('orders', ['id' => $order->id, 'title' => 'Matrix updated order', 'payment_terms' => 7]);

            return;
        }
        $this->delete(route($definition['route'], $order))->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    private function updatePayload(): array
    {
        return ['title' => 'Matrix updated order', 'order_date' => '2026-09-01', 'price' => 150, 'payment_terms' => 7, 'status' => 'completed', 'comment' => 'Matrix'];
    }
}
