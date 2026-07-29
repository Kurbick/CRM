<?php

namespace Tests\Feature\Navigation;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Support\DomainQueryRecorder;

class AuthorizedLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    #[DataProvider('priorityProvider')]
    public function test_exact_permissions_resolve_to_the_first_known_authorized_route(
        array $permissions,
        string $route
    ): void {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        $queries = (new DomainQueryRecorder)->capture(
            fn (): string => app(AuthorizedLandingPage::class)->url($user)
        );

        $this->assertSame(route($route), $queries['result']);
        $this->assertSame([], $queries['records']);
    }

    public function test_dashboard_has_priority_and_request_input_cannot_control_destination(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::InvoicesView->value,
        ]);
        request()->headers->set('referer', 'https://evil.example/redirect');
        request()->merge(['return_url' => 'https://evil.example/redirect']);

        $this->assertSame(route('dashboard'), app(AuthorizedLandingPage::class)->url($user));
    }

    public function test_administrator_lands_on_dashboard_via_existing_gate_before(): void
    {
        $user = User::factory()->create();
        $user->assignRole(SystemRole::Administrator->value);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => app(AuthorizedLandingPage::class)->url($user)
        );
        $this->assertSame(route('dashboard'), $capture['result']);
        $this->assertSame([], $capture['records']);
    }

    public function test_resolver_stays_query_free_with_cold_and_warm_acl_cache_and_permission_changes(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CompaniesView->value);
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('permissions')->unsetRelation('roles');

        $cold = (new DomainQueryRecorder)->capture(
            fn () => app(AuthorizedLandingPage::class)->url($user)
        );
        $this->assertSame(route('companies.index'), $cold['result']);
        $this->assertSame([], $cold['records']);

        $warm = (new DomainQueryRecorder)->capture(
            fn () => app(AuthorizedLandingPage::class)->url($user)
        );
        $this->assertSame(route('companies.index'), $warm['result']);
        $this->assertSame([], $warm['records']);

        $user->revokePermissionTo(PermissionName::CompaniesView->value);
        $user->givePermissionTo(PermissionName::ContractsView->value);
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('permissions')->unsetRelation('roles');
        $revoked = (new DomainQueryRecorder)->capture(
            fn () => app(AuthorizedLandingPage::class)->url($user)
        );
        $this->assertSame(route('contracts.index'), $revoked['result']);
        $this->assertSame([], $revoked['records']);

        $user->givePermissionTo(PermissionName::DashboardView->value);
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('permissions')->unsetRelation('roles');
        $added = (new DomainQueryRecorder)->capture(
            fn () => app(AuthorizedLandingPage::class)->url($user)
        );
        $this->assertSame(route('dashboard'), $added['result']);
        $this->assertSame([], $added['records']);
    }

    public function test_guest_only_route_redirect_and_layout_logo_use_the_same_authorized_landing(): void
    {
        $companyViewer = User::factory()->create();
        $companyViewer->givePermissionTo(PermissionName::CompaniesView->value);

        $this->actingAs($companyViewer)->get(route('login'))
            ->assertRedirect(route('companies.index'));
        $companyLayout = $this->get(route('home'))->assertOk();
        $companyLayout
            ->assertSee('href="'.route('companies.index').'" class="flex items-center gap-2"', false)
            ->assertSee(route('companies.index'), false)
            ->assertDontSee('href="'.route('dashboard').'"', false);

        $mutationOnly = User::factory()->create();
        $mutationOnly->givePermissionTo(PermissionName::CompaniesCreate->value);
        $this->actingAs($mutationOnly)->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('home').'" class="flex items-center gap-2"', false)
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('companies.index').'"', false);
    }

    public function test_dashboard_navigation_and_logo_are_visible_only_with_dashboard_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::DashboardView->value);

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('dashboard').'" class="flex items-center gap-2"', false)
            ->assertSee('href="'.route('dashboard').'"', false);
    }

    public function test_home_is_query_free_and_does_not_disclose_domain_fixtures(): void
    {
        $company = Company::query()->create([
            'name' => 'HOME-COMPANY-MARKER',
            'voen' => 'HOME-VOEN-MARKER',
            'status' => 'active',
        ]);
        CreditBalance::query()->create(['company_id' => $company->id, 'amount' => '9182.73']);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => 'HOME-CONTRACT-MARKER',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        ContractDocument::query()->create([
            'contract_id' => $contract->id,
            'document_type' => 'other',
            'original_name' => 'HOME-DOCUMENT-MARKER.pdf',
            'file_path' => 'contracts/home-document.pdf',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'HOME-INVOICE-MARKER',
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'total_amount' => '731.29',
            'status' => 'issued',
        ]);
        Payment::query()->insert([
            'invoice_id' => $invoice->id,
            'company_id' => $company->id,
            'payment_date' => '2026-07-02',
            'amount' => '213.57',
            'payment_method' => 'transfer',
            'status' => 'pending',
            'comment' => 'HOME-PAYMENT-MARKER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::CompaniesCreate->value);
        $this->actingAs($user);

        $capture = (new DomainQueryRecorder)->capture(fn () => $this->get(route('home')));
        $response = $capture['result']->assertOk();
        foreach ([
            'HOME-COMPANY-MARKER', 'HOME-VOEN-MARKER', '9182.73', 'HOME-CONTRACT-MARKER',
            'HOME-DOCUMENT-MARKER.pdf', 'HOME-INVOICE-MARKER', '731.29', 'HOME-PAYMENT-MARKER', '213.57',
        ] as $marker) {
            $response->assertDontSee($marker, false);
        }
        $response
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('href="'.route('home').'" class="flex items-center gap-2"', false)
            ->assertDontSee(route('companies.index'), false)
            ->assertDontSee(route('contracts.index'), false)
            ->assertDontSee(route('invoices.index'), false);
        $this->assertSame([], $capture['records']);
        $this->get(route('home'))->assertOk();
    }

    public static function priorityProvider(): array
    {
        return [
            'dashboard beats companies' => [[PermissionName::CompaniesView->value, PermissionName::DashboardView->value], 'dashboard'],
            'companies beat contracts' => [[PermissionName::ContractsView->value, PermissionName::CompaniesView->value], 'companies.index'],
            'contracts beat invoices' => [[PermissionName::InvoicesView->value, PermissionName::ContractsView->value], 'contracts.index'],
            'invoices beat users' => [[PermissionName::UsersView->value, PermissionName::InvoicesView->value], 'invoices.index'],
            'users beat roles' => [[PermissionName::RolesView->value, PermissionName::UsersView->value], 'admin.users.index'],
            'roles beat access administration' => [[PermissionName::AccessPermissionsView->value, PermissionName::RolesView->value], 'admin.roles.index'],
            'access administration beats home' => [[PermissionName::AccessPermissionsView->value], 'admin.access-permissions.index'],
            'mutation only' => [[PermissionName::CompaniesCreate->value], 'home'],
            'payments only' => [[PermissionName::PaymentsView->value], 'home'],
            'no permissions' => [[], 'home'],
        ];
    }
}
