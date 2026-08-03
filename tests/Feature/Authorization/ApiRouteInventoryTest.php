<?php

namespace Tests\Feature\Authorization;

use App\Http\Middleware\AuthorizeApiRoute;
use App\Http\Middleware\EnsurePasswordWasChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Access\ApiRouteAuthorizationRegistry;
use App\Support\Access\PermissionRegistry;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_route_is_classified_once_in_the_executable_inventory(): void
    {
        $routes = $this->apiRoutes();
        $routeNames = array_map(
            static fn ($route): ?string => $route->getName(),
            $routes,
        );

        $this->assertNotContains(null, $routeNames);
        $this->assertCount(count(array_unique($routeNames)), $routeNames);

        $classifiedRouteNames = ApiRouteAuthorizationRegistry::classifiedRouteNames();
        $this->assertCount(count(array_unique($classifiedRouteNames)), $classifiedRouteNames);
        $this->assertSame(
            $this->sorted($routeNames),
            $this->sorted($classifiedRouteNames),
        );

        foreach ($routeNames as $routeName) {
            $classification = ApiRouteAuthorizationRegistry::classificationFor($routeName);
            $this->assertContains($classification, [
                'public authentication route',
                'authenticated infrastructure route',
                'permission-protected domain route',
                'unresolved product-decision route',
            ]);

            if ($classification === 'permission-protected domain route') {
                $this->assertTrue(ApiRouteAuthorizationRegistry::has($routeName));
                $this->assertTrue(
                    PermissionRegistry::contains(ApiRouteAuthorizationRegistry::permissionFor($routeName)),
                );
            }

            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotSame('public authentication route', $classification);
            $this->assertNotSame('authenticated infrastructure route', $classification);
            $this->assertStringStartsWith('App\\Http\\Controllers\\', $route->getActionName());

            if (array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
                $this->assertNotSame('public authentication route', $classification);
            }
        }
    }

    public function test_unknown_route_permission_lookup_fails_closed(): void
    {
        $this->expectException(\LogicException::class);

        ApiRouteAuthorizationRegistry::permissionFor('api.route-not-in-inventory');
    }

    public function test_route_inventory_has_no_stale_mappings_or_hidden_unresolved_routes(): void
    {
        $routeNames = array_map(
            static fn ($route): string => (string) $route->getName(),
            $this->apiRoutes(),
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_keys(ApiRouteAuthorizationRegistry::all()), $routeNames)),
        );

        $this->assertSame(
            [
                'api.items.destroy',
                'api.items.show',
                'api.items.update',
                'api.payments.destroy',
                'api.payments.update',
                'api.service-types.destroy',
                'api.service-types.index',
                'api.service-types.items.index',
                'api.service-types.items.store',
                'api.service-types.show',
                'api.service-types.store',
                'api.service-types.update',
            ],
            $this->sorted(ApiRouteAuthorizationRegistry::unresolvedRouteNames()),
        );

        $this->assertSame(
            $this->sorted(ApiRouteAuthorizationRegistry::unresolvedRouteNames()),
            $this->sorted(array_values(array_diff($routeNames, array_keys(ApiRouteAuthorizationRegistry::all())))),
        );
        $this->assertSame([], ApiRouteAuthorizationRegistry::publicRouteNames());
        $this->assertSame([], ApiRouteAuthorizationRegistry::authenticatedOnlyRouteNames());
    }

    public function test_normalized_route_inventory_covers_method_uri_controller_classification_and_permission(): void
    {
        $actual = [];

        foreach ($this->apiRoutes() as $route) {
            $routeName = (string) $route->getName();
            $classification = ApiRouteAuthorizationRegistry::classificationFor($routeName);

            $actual[$routeName] = [
                'method' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $routeName,
                'controller' => $route->getActionName(),
                'classification' => $classification,
                'permission' => $classification === 'permission-protected domain route'
                    ? ApiRouteAuthorizationRegistry::permissionFor($routeName)
                    : null,
            ];
        }

        $this->assertSame($this->sortedRoutes($this->expectedRoutes()), $this->sortedRoutes($actual));
    }

    public function test_all_api_routes_are_fail_closed_through_the_authorization_middleware(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);

            $this->assertContains(Authenticate::class.':sanctum', $middleware, $route->getName());
            $this->assertContains(EnsureUserIsActive::class, $middleware, $route->getName());
            $this->assertContains(EnsurePasswordWasChanged::class, $middleware, $route->getName());
            $this->assertContains(AuthorizeApiRoute::class, $middleware, $route->getName());
            $this->assertContains(SubstituteBindings::class, $middleware, $route->getName());
        }
    }

    public function test_representative_api_routes_have_authorization_before_implicit_bindings(): void
    {
        foreach ([
            'api.companies.index',
            'api.companies.show',
            'api.contracts.orders.index',
            'api.dashboard.company',
            'api.payments.show',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $middleware = app('router')->gatherRouteMiddleware($route);

            $this->assertSame([
                Authenticate::class.':sanctum',
                EnsureUserIsActive::class,
                EnsurePasswordWasChanged::class,
                AuthorizeApiRoute::class,
                SubstituteBindings::class,
            ], $middleware, $routeName);
        }
    }

    public function test_web_middleware_does_not_receive_api_authorization_middleware_or_api_priority_changes(): void
    {
        $route = Route::getRoutes()->getByName('companies.show');
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertNotContains(AuthorizeApiRoute::class, $middleware);
        $this->assertLessThan(
            array_search(EnsureUserIsActive::class, $middleware, true),
            array_search(SubstituteBindings::class, $middleware, true),
        );
    }

    /** @return list<object> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    /** @param list<string|null> $values @return list<string|null> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @param array<string, array<string, mixed>> $routes @return array<string, array<string, mixed>> */
    private function sortedRoutes(array $routes): array
    {
        ksort($routes);

        return $routes;
    }

    /** @return array<string, array{method: string, uri: string, name: string, controller: string, classification: string, permission: string|null}> */
    private function expectedRoutes(): array
    {
        $root = 'App\\Http\\Controllers\\';

        return [
            'api.companies.index' => ['method' => 'GET|HEAD', 'uri' => 'api/companies', 'name' => 'api.companies.index', 'controller' => $root.'CompanyController@index', 'classification' => 'permission-protected domain route', 'permission' => 'companies.view'],
            'api.companies.store' => ['method' => 'POST', 'uri' => 'api/companies', 'name' => 'api.companies.store', 'controller' => $root.'CompanyController@store', 'classification' => 'permission-protected domain route', 'permission' => 'companies.create'],
            'api.companies.show' => ['method' => 'GET|HEAD', 'uri' => 'api/companies/{company}', 'name' => 'api.companies.show', 'controller' => $root.'CompanyController@show', 'classification' => 'permission-protected domain route', 'permission' => 'companies.view'],
            'api.companies.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/companies/{company}', 'name' => 'api.companies.update', 'controller' => $root.'CompanyController@update', 'classification' => 'permission-protected domain route', 'permission' => 'companies.update'],
            'api.companies.destroy' => ['method' => 'DELETE', 'uri' => 'api/companies/{company}', 'name' => 'api.companies.destroy', 'controller' => $root.'CompanyController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'companies.delete'],
            'api.companies.contacts.index' => ['method' => 'GET|HEAD', 'uri' => 'api/companies/{company}/contacts', 'name' => 'api.companies.contacts.index', 'controller' => $root.'CompanyContactController@index', 'classification' => 'permission-protected domain route', 'permission' => 'companies.view'],
            'api.companies.contacts.store' => ['method' => 'POST', 'uri' => 'api/companies/{company}/contacts', 'name' => 'api.companies.contacts.store', 'controller' => $root.'CompanyContactController@store', 'classification' => 'permission-protected domain route', 'permission' => 'company_contacts.create'],
            'api.companies.contracts.index' => ['method' => 'GET|HEAD', 'uri' => 'api/companies/{company}/contracts', 'name' => 'api.companies.contracts.index', 'controller' => $root.'ContractController@index', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.companies.contracts.store' => ['method' => 'POST', 'uri' => 'api/companies/{company}/contracts', 'name' => 'api.companies.contracts.store', 'controller' => $root.'ContractController@store', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.create'],
            'api.companies.invoices.index' => ['method' => 'GET|HEAD', 'uri' => 'api/companies/{company}/invoices', 'name' => 'api.companies.invoices.index', 'controller' => $root.'InvoiceController@index', 'classification' => 'permission-protected domain route', 'permission' => 'invoices.view'],
            'api.companies.invoices.store' => ['method' => 'POST', 'uri' => 'api/companies/{company}/invoices', 'name' => 'api.companies.invoices.store', 'controller' => $root.'InvoiceController@store', 'classification' => 'permission-protected domain route', 'permission' => 'invoices.create'],
            'api.contacts.show' => ['method' => 'GET|HEAD', 'uri' => 'api/contacts/{contact}', 'name' => 'api.contacts.show', 'controller' => $root.'CompanyContactController@show', 'classification' => 'permission-protected domain route', 'permission' => 'companies.view'],
            'api.contacts.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/contacts/{contact}', 'name' => 'api.contacts.update', 'controller' => $root.'CompanyContactController@update', 'classification' => 'permission-protected domain route', 'permission' => 'company_contacts.update'],
            'api.contacts.destroy' => ['method' => 'DELETE', 'uri' => 'api/contacts/{contact}', 'name' => 'api.contacts.destroy', 'controller' => $root.'CompanyContactController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'company_contacts.delete'],
            'api.contracts.show' => ['method' => 'GET|HEAD', 'uri' => 'api/contracts/{contract}', 'name' => 'api.contracts.show', 'controller' => $root.'ContractController@show', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.contracts.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/contracts/{contract}', 'name' => 'api.contracts.update', 'controller' => $root.'ContractController@update', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.update'],
            'api.contracts.destroy' => ['method' => 'DELETE', 'uri' => 'api/contracts/{contract}', 'name' => 'api.contracts.destroy', 'controller' => $root.'ContractController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.delete'],
            'api.contracts.orders.index' => ['method' => 'GET|HEAD', 'uri' => 'api/contracts/{contract}/orders', 'name' => 'api.contracts.orders.index', 'controller' => $root.'OrderController@index', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.contracts.orders.store' => ['method' => 'POST', 'uri' => 'api/contracts/{contract}/orders', 'name' => 'api.contracts.orders.store', 'controller' => $root.'OrderController@store', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.create'],
            'api.contracts.subscriptions.index' => ['method' => 'GET|HEAD', 'uri' => 'api/contracts/{contract}/subscriptions', 'name' => 'api.contracts.subscriptions.index', 'controller' => $root.'SubscriptionController@index', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.contracts.subscriptions.store' => ['method' => 'POST', 'uri' => 'api/contracts/{contract}/subscriptions', 'name' => 'api.contracts.subscriptions.store', 'controller' => $root.'SubscriptionController@store', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.create'],
            'api.dashboard' => ['method' => 'GET|HEAD', 'uri' => 'api/dashboard', 'name' => 'api.dashboard', 'controller' => $root.'DashboardController@overview', 'classification' => 'permission-protected domain route', 'permission' => 'dashboard.view'],
            'api.dashboard.companies' => ['method' => 'GET|HEAD', 'uri' => 'api/dashboard/companies', 'name' => 'api.dashboard.companies', 'controller' => $root.'DashboardController@companies', 'classification' => 'permission-protected domain route', 'permission' => 'dashboard.view'],
            'api.dashboard.company' => ['method' => 'GET|HEAD', 'uri' => 'api/dashboard/companies/{company}', 'name' => 'api.dashboard.company', 'controller' => $root.'DashboardController@company', 'classification' => 'permission-protected domain route', 'permission' => 'dashboard.view'],
            'api.invoices.show' => ['method' => 'GET|HEAD', 'uri' => 'api/invoices/{invoice}', 'name' => 'api.invoices.show', 'controller' => $root.'InvoiceController@show', 'classification' => 'permission-protected domain route', 'permission' => 'invoices.view'],
            'api.invoices.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/invoices/{invoice}', 'name' => 'api.invoices.update', 'controller' => $root.'InvoiceController@update', 'classification' => 'permission-protected domain route', 'permission' => 'invoices.update'],
            'api.invoices.destroy' => ['method' => 'DELETE', 'uri' => 'api/invoices/{invoice}', 'name' => 'api.invoices.destroy', 'controller' => $root.'InvoiceController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'invoices.delete'],
            'api.invoices.payments.index' => ['method' => 'GET|HEAD', 'uri' => 'api/invoices/{invoice}/payments', 'name' => 'api.invoices.payments.index', 'controller' => $root.'PaymentController@index', 'classification' => 'permission-protected domain route', 'permission' => 'payments.view'],
            'api.invoices.payments.store' => ['method' => 'POST', 'uri' => 'api/invoices/{invoice}/payments', 'name' => 'api.invoices.payments.store', 'controller' => $root.'PaymentController@store', 'classification' => 'permission-protected domain route', 'permission' => 'payments.create'],
            'api.items.show' => ['method' => 'GET|HEAD', 'uri' => 'api/items/{item}', 'name' => 'api.items.show', 'controller' => $root.'ServiceTypeItemController@show', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.items.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/items/{item}', 'name' => 'api.items.update', 'controller' => $root.'ServiceTypeItemController@update', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.items.destroy' => ['method' => 'DELETE', 'uri' => 'api/items/{item}', 'name' => 'api.items.destroy', 'controller' => $root.'ServiceTypeItemController@destroy', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.orders.show' => ['method' => 'GET|HEAD', 'uri' => 'api/orders/{order}', 'name' => 'api.orders.show', 'controller' => $root.'OrderController@show', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.orders.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/orders/{order}', 'name' => 'api.orders.update', 'controller' => $root.'OrderController@update', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.update'],
            'api.orders.destroy' => ['method' => 'DELETE', 'uri' => 'api/orders/{order}', 'name' => 'api.orders.destroy', 'controller' => $root.'OrderController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.delete'],
            'api.payments.show' => ['method' => 'GET|HEAD', 'uri' => 'api/payments/{payment}', 'name' => 'api.payments.show', 'controller' => $root.'PaymentController@show', 'classification' => 'permission-protected domain route', 'permission' => 'payments.view'],
            'api.payments.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/payments/{payment}', 'name' => 'api.payments.update', 'controller' => $root.'PaymentController@update', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.payments.destroy' => ['method' => 'DELETE', 'uri' => 'api/payments/{payment}', 'name' => 'api.payments.destroy', 'controller' => $root.'PaymentController@destroy', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.index' => ['method' => 'GET|HEAD', 'uri' => 'api/service-types', 'name' => 'api.service-types.index', 'controller' => $root.'ServiceTypeController@index', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.store' => ['method' => 'POST', 'uri' => 'api/service-types', 'name' => 'api.service-types.store', 'controller' => $root.'ServiceTypeController@store', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.show' => ['method' => 'GET|HEAD', 'uri' => 'api/service-types/{service_type}', 'name' => 'api.service-types.show', 'controller' => $root.'ServiceTypeController@show', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/service-types/{service_type}', 'name' => 'api.service-types.update', 'controller' => $root.'ServiceTypeController@update', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.destroy' => ['method' => 'DELETE', 'uri' => 'api/service-types/{service_type}', 'name' => 'api.service-types.destroy', 'controller' => $root.'ServiceTypeController@destroy', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.items.index' => ['method' => 'GET|HEAD', 'uri' => 'api/service-types/{service_type}/items', 'name' => 'api.service-types.items.index', 'controller' => $root.'ServiceTypeItemController@index', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.service-types.items.store' => ['method' => 'POST', 'uri' => 'api/service-types/{service_type}/items', 'name' => 'api.service-types.items.store', 'controller' => $root.'ServiceTypeItemController@store', 'classification' => 'unresolved product-decision route', 'permission' => null],
            'api.subscriptions.show' => ['method' => 'GET|HEAD', 'uri' => 'api/subscriptions/{subscription}', 'name' => 'api.subscriptions.show', 'controller' => $root.'SubscriptionController@show', 'classification' => 'permission-protected domain route', 'permission' => 'contracts.view'],
            'api.subscriptions.update' => ['method' => 'PUT|PATCH', 'uri' => 'api/subscriptions/{subscription}', 'name' => 'api.subscriptions.update', 'controller' => $root.'SubscriptionController@update', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.update'],
            'api.subscriptions.destroy' => ['method' => 'DELETE', 'uri' => 'api/subscriptions/{subscription}', 'name' => 'api.subscriptions.destroy', 'controller' => $root.'SubscriptionController@destroy', 'classification' => 'permission-protected domain route', 'permission' => 'contract_subjects.delete'],
        ];
    }
}
