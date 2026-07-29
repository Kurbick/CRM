<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\SubscriptionController;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    private const MATRIX = [
        ['route' => 'contracts.subscriptions.store', 'methods' => ['POST'], 'controller' => SubscriptionController::class, 'ability' => 'create', 'target' => 'subscription_create', 'permission' => PermissionName::ContractSubjectsCreate->value, 'wrong_permission' => PermissionName::ContractSubjectsUpdate->value, 'scenario' => 'store'],
        ['route' => 'subscriptions.update', 'methods' => ['PUT'], 'controller' => SubscriptionController::class, 'ability' => 'update', 'target' => 'subscription_model', 'permission' => PermissionName::ContractSubjectsUpdate->value, 'wrong_permission' => PermissionName::ContractSubjectsCreate->value, 'scenario' => 'update'],
        ['route' => 'subscriptions.destroy', 'methods' => ['DELETE'], 'controller' => SubscriptionController::class, 'ability' => 'delete', 'target' => 'subscription_model', 'permission' => PermissionName::ContractSubjectsDelete->value, 'wrong_permission' => PermissionName::ContractSubjectsUpdate->value, 'scenario' => 'destroy'],
    ];

    public function test_every_subscription_mutation_route_is_in_matrix(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getControllerClass() === SubscriptionController::class
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
        $contract = $this->contract($this->company('Subscription matrix target '.uniqid()));

        return match ($target) {
            'subscription_create' => [Subscription::class, $contract],
            'subscription_model' => $this->subjectSubscription($contract),
            default => $this->fail("Unknown Subscription Gate target [{$target}]."),
        };
    }

    private function assertForbiddenHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Subscription forbidden '.uniqid()));
        if ($definition['scenario'] === 'store') {
            $subjects = DB::table('subscriptions')->count();
            $types = DB::table('service_types')->count();
            $marker = 'FORBIDDEN-SUB-MATRIX-'.uniqid();
            $this->post(route($definition['route'], $contract), $this->subscriptionPayload($marker))->assertForbidden();
            $this->assertSame($subjects, DB::table('subscriptions')->count());
            $this->assertSame($types, DB::table('service_types')->count());
            $this->assertDatabaseMissing('service_types', ['name' => $marker]);

            return;
        }
        $subscription = $this->subjectSubscription($contract);
        if ($definition['scenario'] === 'update') {
            $original = $subscription->fresh()->getAttributes();
            $invoice = $contract->invoices()->create([
                'company_id' => $contract->company_id, 'invoice_number' => 'SUB-MATRIX-'.uniqid(),
                'issue_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => 100, 'status' => 'issued',
            ]);
            $invoice->lines()->create(['subscription_id' => $subscription->id, 'description' => 'Subscription', 'amount' => 100, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
            $this->put(route($definition['route'], $subscription), $this->updatePayload())->assertForbidden();
            $this->assertSame($original, $subscription->fresh()->getAttributes());
            $this->assertSame('2026-08-31', $invoice->fresh()->due_date);

            return;
        }
        $chain = $this->subjectFinancialChain($subscription);
        $this->delete(route($definition['route'], $subscription))->assertForbidden();
        $this->assertSubjectFinancialChainExists($subscription, $chain);
    }

    private function assertAllowedHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Subscription allowed '.uniqid()));
        if ($definition['scenario'] === 'store') {
            $marker = 'ALLOWED-SUB-MATRIX-'.uniqid();
            $this->post(route($definition['route'], $contract), $this->subscriptionPayload($marker))->assertRedirect(route('dashboard'));
            $this->assertDatabaseHas('subscriptions', ['contract_id' => $contract->id]);
            $this->assertDatabaseHas('service_types', ['name' => $marker, 'type' => 'subscription']);

            return;
        }
        $subscription = $this->subjectSubscription($contract);
        if ($definition['scenario'] === 'update') {
            $this->put(route($definition['route'], $subscription), $this->updatePayload())->assertRedirect(route('dashboard'));
            $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'title' => 'Matrix updated subscription', 'payment_terms' => 7]);

            return;
        }
        $this->delete(route($definition['route'], $subscription))->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);
    }

    private function updatePayload(): array
    {
        return ['title' => 'Matrix updated subscription', 'start_date' => '2026-09-01', 'billing_period' => 'quarterly', 'amount' => 150, 'payment_terms' => 7, 'status' => 'suspended', 'comment' => 'Matrix'];
    }
}
