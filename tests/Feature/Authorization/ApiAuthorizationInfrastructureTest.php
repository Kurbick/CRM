<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\InvoicePaymentAllocationWriter;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\DomainQueryRecorder;

class ApiAuthorizationInfrastructureTest extends AuthorizationTestCase
{
    public function test_guest_is_rejected_by_sanctum_before_api_authorization(): void
    {
        $response = $this->getJson(route('api.companies.index'));

        $response->assertUnauthorized();
    }

    public function test_inactive_user_is_rejected_before_api_authorization(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.companies.index'))
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }

    public function test_temporary_password_user_is_rejected_before_api_authorization(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.companies.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_active_user_without_permission_is_rejected_without_domain_queries(): void
    {
        $company = Company::query()->create([
            'name' => 'API authorization target',
            'status' => 'active',
            'invoice_mode' => 'separate',
        ]);
        $this->actingAsPermissions();

        $existing = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.show', $company)),
        );
        $missing = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.show', ['company' => $company->id + 1000000])),
        );

        $existing['result']->assertForbidden();
        $missing['result']->assertForbidden();
        $this->assertSame($existing['result']->status(), $missing['result']->status());
        $this->assertSame(
            $this->withoutDebugTrace($existing['result']->json()),
            $this->withoutDebugTrace($missing['result']->json()),
        );
        $this->assertSame([], $existing['records']);
        $this->assertSame([], $missing['records']);
    }

    public function test_wrong_neighbor_permission_is_rejected_before_nested_binding_and_controller(): void
    {
        $company = Company::query()->create([
            'name' => 'API nested authorization target',
            'status' => 'active',
            'invoice_mode' => 'separate',
        ]);
        $this->actingAsPermissions([PermissionName::CompaniesUpdate->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.contracts.index', $company)),
        );

        $capture['result']->assertForbidden();
        $this->assertSame([], $capture['records']);

        $confirmCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(route('api.payments.confirm', ['payment' => 1000000]), [
                'status' => 'confirmed',
            ]),
        );

        $confirmCapture['result']->assertForbidden();
        $this->assertSame([], $confirmCapture['records']);
    }

    public function test_exact_permission_reaches_substitute_bindings_after_authorization(): void
    {
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->getJson(route('api.companies.show', ['company' => 1000000]))
            ->assertNotFound();
    }

    public function test_custom_role_with_exact_permission_is_allowed_without_role_name_condition(): void
    {
        $role = Role::query()->create([
            'name' => 'api-inventory-custom-'.uniqid(),
            'guard_name' => 'web',
            'display_name' => 'API inventory custom role',
        ]);
        $role->givePermissionTo(PermissionName::CompaniesView->value);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        $this->getJson(route('api.companies.index'))->assertOk();
        $this->assertFalse($user->hasRole('administrator'));
    }

    public function test_administrator_uses_existing_gate_before_bypass(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user, 'web');

        $this->getJson(route('api.companies.index'))->assertOk();
    }

    public function test_unresolved_numeric_mutation_route_fails_closed_before_binding_validation_and_controller(): void
    {
        $serviceType = ServiceType::query()->create([
            'name' => 'Unresolved API authorization target',
            'type' => 'one_time',
            'base_price' => '100.00',
        ]);
        $this->actingAsPermissions();

        $existing = (new DomainQueryRecorder)->capture(
            fn () => $this->putJson(route('api.service-types.update', $serviceType), []),
        );
        $missing = (new DomainQueryRecorder)->capture(
            fn () => $this->putJson(route('api.service-types.update', [
                'service_type' => $serviceType->id + 1_000_000,
            ]), []),
        );

        $existing['result']->assertForbidden()->assertJsonMissingValidationErrors();
        $missing['result']->assertForbidden()->assertJsonMissingValidationErrors();
        $this->assertSame($existing['result']->status(), $missing['result']->status());
        $this->assertSame(
            $this->withoutDebugTrace($existing['result']->json()),
            $this->withoutDebugTrace($missing['result']->json()),
        );
        $this->assertSame([], $existing['records']);
        $this->assertSame([], $missing['records']);
    }

    public function test_unauthorized_mutation_is_rejected_before_validation_and_domain_queries(): void
    {
        $this->actingAsPermissions();

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(route('api.companies.store'), []),
        );

        $capture['result']->assertForbidden()->assertJsonMissingValidationErrors();
        $this->assertSame([], $capture['records']);
    }

    public function test_unauthorized_financial_request_does_not_call_storage_or_allocation_service(): void
    {
        $this->actingAsPermissions();
        Storage::shouldReceive('disk')->never();

        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->never();
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(route('api.invoices.payments.store', ['invoice' => 1000000]), [
                'payment_date' => '2026-08-01',
                'amount' => '10.00',
                'payment_method' => 'cash',
                'status' => 'confirmed',
            ]),
        );

        $capture['result']->assertForbidden();
        $this->assertSame([], $capture['records']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutDebugTrace(array $payload): array
    {
        unset($payload['trace']);

        return $payload;
    }
}
