<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class AuthenticationRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_routes_and_disabled_features_are_explicit(): void
    {
        $this->assertContains('guest', RouteFacade::getRoutes()->getByName('login')->gatherMiddleware());
        $this->assertContains('POST', RouteFacade::getRoutes()->getByName('logout')->methods());
        $this->assertNotContains('password.changed', RouteFacade::getRoutes()->getByName('password.change')->gatherMiddleware());

        foreach (['register', 'register.store', 'password.request', 'password.reset', 'verification.notice', 'two-factor.login', 'passkey.login'] as $name) {
            $this->assertNull(RouteFacade::getRoutes()->getByName($name), "Unexpected route {$name}");
        }
    }

    public function test_all_project_web_and_api_routes_are_protected(): void
    {
        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($name, 'api.')) {
                $this->assertContains('auth:sanctum', $middleware, $name);
                continue;
            }

            if ($name === 'dashboard' || preg_match('/^(companies|contacts|contracts|contract-documents|orders|subscriptions|invoices|payments|ajax)\./', $name)) {
                $this->assertContains('auth', $middleware, $name);
                $this->assertContains('password.changed', $middleware, $name);
            }
        }
    }

    public function test_guest_is_redirected_from_representative_crm_routes_and_health_is_public(): void
    {
        foreach ([route('dashboard'), route('companies.index'), route('contracts.index'), route('invoices.index')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->post(route('companies.store'), [])->assertRedirect(route('login'));
        $this->get('/up')->assertOk();
    }
}
