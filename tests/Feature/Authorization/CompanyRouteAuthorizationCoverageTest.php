<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\CompanyContactController;
use App\Http\Controllers\Web\CompanyController;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class CompanyRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    /** @var array<string, array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}> */
    private const AUTHORIZATION_MATRIX = [
        'companies.store' => [
            'methods' => ['POST'],
            'ability' => 'create',
            'permission' => PermissionName::CompaniesCreate->value,
            'wrong_permission' => PermissionName::CompaniesUpdate->value,
            'target' => Company::class,
            'scenario' => 'company_store',
        ],
        'companies.update' => [
            'methods' => ['PUT', 'PATCH'],
            'ability' => 'update',
            'permission' => PermissionName::CompaniesUpdate->value,
            'wrong_permission' => PermissionName::CompaniesCreate->value,
            'target' => Company::class,
            'scenario' => 'company_update',
        ],
        'companies.destroy' => [
            'methods' => ['DELETE'],
            'ability' => 'delete',
            'permission' => PermissionName::CompaniesDelete->value,
            'wrong_permission' => PermissionName::CompaniesUpdate->value,
            'target' => Company::class,
            'scenario' => 'company_destroy',
        ],
        'companies.contacts.store' => [
            'methods' => ['POST'],
            'ability' => 'create',
            'permission' => PermissionName::CompanyContactsCreate->value,
            'wrong_permission' => PermissionName::CompanyContactsUpdate->value,
            'target' => CompanyContact::class,
            'scenario' => 'contact_store',
        ],
        'contacts.update' => [
            'methods' => ['PUT'],
            'ability' => 'update',
            'permission' => PermissionName::CompanyContactsUpdate->value,
            'wrong_permission' => PermissionName::CompanyContactsCreate->value,
            'target' => CompanyContact::class,
            'scenario' => 'contact_update',
        ],
        'contacts.destroy' => [
            'methods' => ['DELETE'],
            'ability' => 'delete',
            'permission' => PermissionName::CompanyContactsDelete->value,
            'wrong_permission' => PermissionName::CompanyContactsUpdate->value,
            'target' => CompanyContact::class,
            'scenario' => 'contact_destroy',
        ],
    ];

    public function test_every_company_mutation_route_is_in_executable_authorization_matrix(): void
    {
        $controllers = [CompanyController::class, CompanyContactController::class];
        $stateChangingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($controllers, $stateChangingMethods): bool {
                return in_array($route->getControllerClass(), $controllers, true)
                    && array_intersect($stateChangingMethods, $route->methods()) !== [];
            })
            ->keyBy(fn ($route): string => (string) $route->getName());

        $this->assertSame(
            collect(array_keys(self::AUTHORIZATION_MATRIX))->sort()->values()->all(),
            $routes->keys()->sort()->values()->all()
        );

        foreach (self::AUTHORIZATION_MATRIX as $routeName => $definition) {
            $routeMethods = array_values(array_intersect(
                $stateChangingMethods,
                $routes->get($routeName)->methods()
            ));
            sort($routeMethods);
            $expectedMethods = $definition['methods'];
            sort($expectedMethods);

            $this->assertSame($expectedMethods, $routeMethods);
            $this->assertNotSame($definition['permission'], $definition['wrong_permission']);
            $this->assertSame(
                $definition['target'] === Company::class
                    ? CompanyController::class
                    : CompanyContactController::class,
                $routes->get($routeName)->getControllerClass()
            );
        }
    }

    /**
     * @param  array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}  $definition
     */
    #[DataProvider('mutationProvider')]
    public function test_matrix_route_rejects_without_permission_and_preserves_database(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions();

        $this->assertFalse(Gate::forUser($user)->allows(
            $definition['ability'],
            $this->resolvePolicyTarget($definition)
        ));
        $this->assertScenarioForbidden($routeName, $definition['scenario']);
    }

    /**
     * @param  array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}  $definition
     */
    #[DataProvider('mutationProvider')]
    public function test_matrix_route_rejects_neighboring_permission_and_preserves_database(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions([$definition['wrong_permission']]);

        $this->assertFalse(Gate::forUser($user)->allows(
            $definition['ability'],
            $this->resolvePolicyTarget($definition)
        ));
        $this->assertScenarioForbidden($routeName, $definition['scenario']);
    }

    /**
     * @param  array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}  $definition
     */
    #[DataProvider('mutationProvider')]
    public function test_matrix_route_allows_exact_permission_and_executes_mutation(
        string $routeName,
        array $definition
    ): void {
        $user = $this->actingAsPermissions([$definition['permission']]);

        $this->assertTrue($user->can($definition['permission']));
        $this->assertFalse($user->can($definition['wrong_permission']));
        $this->assertTrue(Gate::forUser($user)->allows(
            $definition['ability'],
            $this->resolvePolicyTarget($definition)
        ));
        $this->assertScenarioAllowed($routeName, $definition['scenario']);
    }

    /**
     * @param  array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}  $definition
     */
    private function resolvePolicyTarget(array $definition): mixed
    {
        $company = $this->company('Matrix policy target '.uniqid());

        if ($definition['target'] === Company::class) {
            return $definition['ability'] === 'create'
                ? Company::class
                : $company;
        }

        if ($definition['target'] === CompanyContact::class) {
            return $definition['ability'] === 'create'
                ? [CompanyContact::class, $company]
                : $this->contact($company, 'Matrix policy target contact');
        }

        $this->fail('Unsupported policy target in the authorization matrix.');
    }

    private function assertScenarioForbidden(string $routeName, string $scenario): void
    {
        match ($scenario) {
            'company_store' => $this->assertCompanyStoreForbidden($routeName),
            'company_update' => $this->assertCompanyUpdateForbidden($routeName),
            'company_destroy' => $this->assertCompanyDestroyForbidden($routeName),
            'contact_store' => $this->assertContactStoreForbidden($routeName),
            'contact_update' => $this->assertContactUpdateForbidden($routeName),
            'contact_destroy' => $this->assertContactDestroyForbidden($routeName),
        };
    }

    private function assertScenarioAllowed(string $routeName, string $scenario): void
    {
        match ($scenario) {
            'company_store' => $this->assertCompanyStoreAllowed($routeName),
            'company_update' => $this->assertCompanyUpdateAllowed($routeName),
            'company_destroy' => $this->assertCompanyDestroyAllowed($routeName),
            'contact_store' => $this->assertContactStoreAllowed($routeName),
            'contact_update' => $this->assertContactUpdateAllowed($routeName),
            'contact_destroy' => $this->assertContactDestroyAllowed($routeName),
        };
    }

    /** @return array<string, array{string, array{methods: list<string>, ability: string, permission: string, wrong_permission: string, target: class-string, scenario: string}}> */
    public static function mutationProvider(): array
    {
        $cases = [];

        foreach (self::AUTHORIZATION_MATRIX as $routeName => $definition) {
            $cases[$routeName] = [$routeName, $definition];
        }

        return $cases;
    }

    private function assertCompanyStoreForbidden(string $routeName): void
    {
        $count = DB::table('companies')->count();
        $payload = $this->companyPayload('FORBIDDEN-COMPANY-STORE');

        $this->post(route($routeName), $payload)->assertForbidden();

        $this->assertSame($count, DB::table('companies')->count());
        $this->assertDatabaseMissing('companies', ['name' => 'FORBIDDEN-COMPANY-STORE']);
        $this->assertDatabaseMissing('companies', ['voen' => $payload['voen']]);
    }

    private function assertCompanyUpdateForbidden(string $routeName): void
    {
        $company = $this->company('ORIGINAL-COMPANY-NAME');
        $company->forceFill([
            'status' => 'active',
            'iban' => 'AZ00ORIGINAL',
            'comment' => 'ORIGINAL-COMPANY-COMMENT',
        ])->save();
        $payload = $this->companyPayload('MUTATED-COMPANY-NAME');
        $payload['status'] = 'archived';
        $payload['iban'] = 'AZ00MUTATED';
        $payload['comment'] = 'MUTATED-COMPANY-COMMENT';

        $this->put(route($routeName, $company), $payload)->assertForbidden();

        $fresh = $company->fresh();
        $this->assertSame('ORIGINAL-COMPANY-NAME', $fresh->name);
        $this->assertSame('active', $fresh->status);
        $this->assertSame('AZ00ORIGINAL', $fresh->iban);
        $this->assertSame('ORIGINAL-COMPANY-COMMENT', $fresh->comment);
    }

    private function assertCompanyDestroyForbidden(string $routeName): void
    {
        $company = $this->company('FORBIDDEN-EMPTY-DESTROY');

        $this->delete(route($routeName, $company))->assertForbidden();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'FORBIDDEN-EMPTY-DESTROY',
        ]);
    }

    private function assertContactStoreForbidden(string $routeName): void
    {
        $company = $this->company('Forbidden contact store parent');
        $count = DB::table('company_contacts')->count();

        $this->post(route($routeName, $company), $this->contactPayload('FORBIDDEN-CONTACT-STORE'))
            ->assertForbidden();

        $this->assertSame($count, DB::table('company_contacts')->count());
        $this->assertDatabaseMissing('company_contacts', [
            'company_id' => $company->id,
            'first_name' => 'FORBIDDEN-CONTACT-STORE',
        ]);
    }

    private function assertContactUpdateForbidden(string $routeName): void
    {
        $companyA = $this->company('Forbidden update parent A');
        $companyB = $this->company('Forbidden update parent B');
        $contact = $this->contact($companyA, 'ORIGINAL-CONTACT-NAME');

        $this->put(route($routeName, $contact), [
            ...$this->contactPayload('MUTATED-CONTACT-NAME'),
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $fresh = $contact->fresh();
        $this->assertSame('ORIGINAL-CONTACT-NAME', $fresh->first_name);
        $this->assertSame('+994500000001', $fresh->phone);
        $this->assertSame('Original contact comment', $fresh->comment);
        $this->assertSame($companyA->id, $fresh->company_id);
    }

    private function assertContactDestroyForbidden(string $routeName): void
    {
        $company = $this->company('Forbidden contact destroy parent');
        $contact = $this->contact($company, 'FORBIDDEN-CONTACT-DESTROY');

        $this->delete(route($routeName, $contact))->assertForbidden();

        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
            'first_name' => 'FORBIDDEN-CONTACT-DESTROY',
        ]);
    }

    private function assertCompanyStoreAllowed(string $routeName): void
    {
        $payload = $this->companyPayload('ALLOWED-COMPANY-STORE');

        $this->post(route($routeName), $payload)->assertRedirect(route('home'));

        $this->assertDatabaseHas('companies', [
            'name' => 'ALLOWED-COMPANY-STORE',
            'voen' => $payload['voen'],
        ]);
    }

    private function assertCompanyUpdateAllowed(string $routeName): void
    {
        $company = $this->company('ALLOWED-ORIGINAL-COMPANY');
        $company->forceFill([
            'status' => 'active',
            'iban' => 'AZ00ALLOWEDORIGINAL',
            'comment' => 'ALLOWED-ORIGINAL-COMMENT',
        ])->save();
        $payload = $this->companyPayload('ALLOWED-MUTATED-COMPANY');
        $payload['status'] = 'archived';
        $payload['iban'] = 'AZ00ALLOWEDMUTATED';
        $payload['comment'] = 'ALLOWED-MUTATED-COMMENT';

        $this->put(route($routeName, $company), $payload)->assertRedirect(route('home'));

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'ALLOWED-MUTATED-COMPANY',
            'status' => 'archived',
            'iban' => 'AZ00ALLOWEDMUTATED',
            'comment' => 'ALLOWED-MUTATED-COMMENT',
        ]);
    }

    private function assertCompanyDestroyAllowed(string $routeName): void
    {
        $company = $this->company('ALLOWED-EMPTY-DESTROY');

        $this->delete(route($routeName, $company))->assertRedirect(route('home'));

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    private function assertContactStoreAllowed(string $routeName): void
    {
        $company = $this->company('Allowed contact store parent');

        $this->post(route($routeName, $company), $this->contactPayload('ALLOWED-CONTACT-STORE'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('company_contacts', [
            'company_id' => $company->id,
            'first_name' => 'ALLOWED-CONTACT-STORE',
        ]);
    }

    private function assertContactUpdateAllowed(string $routeName): void
    {
        $company = $this->company('Allowed update parent');
        $contact = $this->contact($company, 'ALLOWED-ORIGINAL-CONTACT');

        $this->put(route($routeName, $contact), $this->contactPayload('ALLOWED-MUTATED-CONTACT'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
            'first_name' => 'ALLOWED-MUTATED-CONTACT',
            'phone' => '+994500000003',
            'comment' => 'Updated contact comment',
        ]);
    }

    private function assertContactDestroyAllowed(string $routeName): void
    {
        $company = $this->company('Allowed contact destroy parent');
        $contact = $this->contact($company, 'ALLOWED-CONTACT-DESTROY');

        $this->delete(route($routeName, $contact))->assertRedirect(route('home'));

        $this->assertDatabaseMissing('company_contacts', ['id' => $contact->id]);
    }
}
