<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

class ContractAuthorizationTest extends AuthorizationTestCase
{
    public function test_read_routes_require_contract_view(): void
    {
        $company = $this->company('Hidden contract company');
        $contract = $this->contract($company);
        $this->actingAsPermissions();

        $this->get(route('contracts.index'))->assertForbidden();
        $this->get(route('contracts.show', $contract))->assertForbidden();
        $this->get(route('contracts.create'))->assertForbidden();
        $this->get(route('companies.contracts.create', $company))->assertForbidden();
        $this->get(route('contracts.edit', $contract))->assertForbidden();
    }

    public function test_view_permission_reveals_only_minimal_company_context_without_company_view(): void
    {
        [$company, $secrets] = $this->companyWithSecrets('VIEW');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        foreach ([
            $this->get(route('contracts.index')),
            $this->get(route('contracts.show', $contract)),
        ] as $response) {
            $response->assertOk()
                ->assertSee($company->name)
                ->assertDontSee(route('companies.show', $company), false);
            $this->assertCompanySecretsAbsent($response, $secrets);
        }
    }

    public function test_create_only_user_can_use_both_forms_and_store_without_company_view_or_contract_view(): void
    {
        [$company, $secrets] = $this->companyWithSecrets('CREATE');
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $generalCreate = $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSee($company->name)
            ->assertSee('value="'.$company->id.'"', false)
            ->assertDontSee(route('companies.show', $company), false)
            ->assertSee(route('home'), false);
        $this->assertCompanySecretsAbsent($generalCreate, $secrets);

        $nestedCreate = $this->get(route('companies.contracts.create', $company))
            ->assertOk()
            ->assertSee($company->name)
            ->assertSee('name="company_id" value="'.$company->id.'"', false)
            ->assertDontSee(route('companies.show', $company), false)
            ->assertSee(route('home'), false);
        $this->assertCompanySecretsAbsent($nestedCreate, $secrets);

        $payload = $this->contractPayload($company, 'CREATE-ONLY');
        $this->post(route('contracts.store'), $payload)
            ->assertRedirect(route('home'));
        $this->assertDatabaseHas('contracts', [
            'company_id' => $company->id,
            'contract_number' => $payload['contract_number'],
        ]);
    }

    public function test_update_only_user_cannot_change_company_and_redirects_safely(): void
    {
        [$company, $secrets] = $this->companyWithSecrets('UPDATE');
        $other = $this->company('UPDATE-ONLY-OTHER');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $edit = $this->get(route('contracts.edit', $contract))
            ->assertOk()
            ->assertSee($company->name)
            ->assertDontSee(route('companies.show', $company), false)
            ->assertDontSee('name="company_id"', false)
            ->assertSee(route('home'), false);
        $this->assertCompanySecretsAbsent($edit, $secrets);
        $this->put(route('contracts.update', $contract), [
            'company_id' => $other->id,
            'contract_number' => 'UPDATE-ONLY-NUMBER',
            'start_date' => '2026-09-01',
            'end_date' => '2027-09-01',
            'status' => 'terminated',
            'comment' => 'Updated safely',
        ])->assertRedirect(route('home'));

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'company_id' => $company->id,
            'contract_number' => 'UPDATE-ONLY-NUMBER',
            'status' => 'terminated',
            'comment' => 'Updated safely',
        ]);
    }

    public function test_delete_only_user_deletes_empty_contract_and_redirects_safely(): void
    {
        $contract = $this->contract($this->company('Delete only company'));
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->delete(route('contracts.destroy', $contract))
            ->assertRedirect(route('home'))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
    }

    public function test_mutation_redirects_prefer_allowed_contract_then_company_then_dashboard(): void
    {
        $company = $this->company('Redirect order company');
        $this->actingAsPermissions([
            PermissionName::ContractsCreate->value,
            PermissionName::CompaniesView->value,
        ]);
        $payload = $this->contractPayload($company, 'REDIRECT-CREATE');

        $this->post(route('contracts.store'), $payload)
            ->assertRedirect(route('companies.show', $company));

        $contract = Contract::query()->where('contract_number', $payload['contract_number'])->firstOrFail();
        $this->actingAsPermissions([
            PermissionName::ContractsUpdate->value,
            PermissionName::CompaniesView->value,
        ]);
        $this->put(route('contracts.update', $contract), [
            'contract_number' => $contract->contract_number,
            'start_date' => '2026-08-02',
            'status' => 'active',
        ])->assertRedirect(route('companies.show', $company));

        $this->actingAsPermissions([
            PermissionName::ContractsDelete->value,
            PermissionName::CompaniesView->value,
        ]);
        $this->delete(route('contracts.destroy', $contract))
            ->assertRedirect(route('companies.show', $company));
    }

    public function test_index_pagination_uses_only_normalized_whitelisted_filters(): void
    {
        $company = $this->company('Pagination company');
        foreach (range(1, 16) as $number) {
            Contract::query()->create([
                'company_id' => $company->id,
                'contract_number' => sprintf('PAGE-CONTRACT-%02d', $number),
                'start_date' => '2026-08-01',
                'status' => 'active',
            ]);
        }
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $response = $this->get(route('contracts.index', [
            'search' => 'PAGE-CONTRACT',
            'status' => 'active',
            'company_id' => $company->id,
            'sort_by' => 'forbidden-column',
            'sort_direction' => 'sideways',
            'unknown' => 'SECRET-QUERY-PARAMETER',
        ]))->assertOk();

        $nextPageUrl = $response->viewData('contracts')->nextPageUrl();
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('search=PAGE-CONTRACT', $nextPageUrl);
        $this->assertStringContainsString('status=active', $nextPageUrl);
        $this->assertStringContainsString('company_id='.$company->id, $nextPageUrl);
        $this->assertStringContainsString('sort_by=start_date', $nextPageUrl);
        $this->assertStringContainsString('sort_direction=desc', $nextPageUrl);
        $this->assertStringNotContainsString('unknown', $nextPageUrl);
        $this->assertStringNotContainsString('SECRET-QUERY-PARAMETER', $nextPageUrl);

        $this->get($nextPageUrl)
            ->assertOk()
            ->assertSee('PAGE-CONTRACT-01')
            ->assertDontSee('SECRET-QUERY-PARAMETER');
    }

    #[DataProvider('dependencyProvider')]
    public function test_administrator_cannot_bypass_each_delete_business_restriction(
        string $dependency
    ): void {
        Storage::fake('local');
        $contract = $this->contract($this->company('Admin dependency '.$dependency));
        $dependencyId = $this->createDependency($contract, $dependency);
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');

        $this->delete(route('contracts.destroy', $contract))
            ->assertRedirect(route('contracts.show', $contract))
            ->assertSessionHas(
                'error',
                'Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.'
            );

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas($dependency, ['id' => $dependencyId]);
        if ($dependency === 'contract_documents') {
            Storage::disk('local')->assertExists('contracts/protected.pdf');
        }
    }

    public function test_contract_ui_uses_independent_permissions_and_business_delete_boolean(): void
    {
        $contract = $this->contract($this->company('Contract UI'));
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee(route('contracts.create'), false)
            ->assertDontSee(route('contracts.edit', $contract), false);
        $this->get(route('contracts.show', $contract))
            ->assertDontSee(route('contracts.edit', $contract), false)
            ->assertDontSee("confirm('Удалить договор?')", false);

        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractsCreate->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::ContractsDelete->value,
        ]);
        $this->get(route('contracts.index'))
            ->assertSee(route('contracts.create'), false)
            ->assertSee(route('contracts.edit', ['contract' => $contract, 'edit_origin' => 'index']), false);
        $this->get(route('contracts.show', $contract))
            ->assertSee(route('contracts.edit', ['contract' => $contract, 'edit_origin' => 'show']), false)
            ->assertSee("confirm('Удалить договор?')", false)
            ->assertSee('action="'.route('contracts.destroy', $contract).'"', false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    #[DataProvider('dependencyProvider')]
    public function test_delete_button_is_hidden_for_each_blocking_dependency(
        string $dependency
    ): void {
        $contract = $this->contract($this->company('UI dependency '.$dependency));
        $this->createDependency($contract, $dependency);
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractsDelete->value,
        ]);

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertDontSee("confirm('Удалить договор?')", false)
            ->assertDontSee('action="'.route('contracts.destroy', $contract).'"', false);
    }

    public function test_delete_button_is_present_for_empty_contract_with_exact_permissions(): void
    {
        $contract = $this->contract($this->company('UI empty deletable contract'));
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractsDelete->value,
        ]);

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee("confirm('Удалить договор?')", false)
            ->assertSee('action="'.route('contracts.destroy', $contract).'"', false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    public function test_viewer_and_accountant_follow_seeded_read_only_contract_matrix(): void
    {
        $contract = $this->contract($this->company('Seeded contract roles'));

        foreach (['viewer', 'accountant'] as $roleName) {
            $user = User::factory()->create();
            $user->assignRole(Role::findByName($roleName));
            $this->actingAs($user, 'web');

            $this->assertTrue($user->can(PermissionName::ContractsView->value));
            $this->assertFalse($user->can(PermissionName::ContractsCreate->value));
            $this->assertFalse($user->can(PermissionName::ContractsUpdate->value));
            $this->assertFalse($user->can(PermissionName::ContractsDelete->value));
            $this->get(route('contracts.index'))->assertOk();
            $this->get(route('contracts.show', $contract))->assertOk();
            $this->get(route('contracts.create'))->assertForbidden();
            $this->get(route('contracts.edit', $contract))->assertForbidden();
            $payload = $this->contractPayload($contract->company, 'ROLE-FORBIDDEN-'.$roleName);
            $this->post(route('contracts.store'), $payload)->assertForbidden();
            $this->put(route('contracts.update', $contract), [
                'contract_number' => 'ROLE-MUTATED-'.$roleName,
                'start_date' => '2026-09-01',
                'status' => 'terminated',
            ])->assertForbidden();
            $this->delete(route('contracts.destroy', $contract))->assertForbidden();
            $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
            $this->assertDatabaseMissing('contracts', ['contract_number' => $payload['contract_number']]);
            $this->assertNotSame('ROLE-MUTATED-'.$roleName, $contract->fresh()->contract_number);
        }
    }

    public function test_administrator_passes_all_contract_permission_abilities(): void
    {
        $contract = $this->contract($this->company('Administrator policy contract'));
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));

        $this->assertTrue(Gate::forUser($administrator)->allows('viewAny', Contract::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('view', $contract));
        $this->assertTrue(Gate::forUser($administrator)->allows('create', Contract::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('update', $contract));
        $this->assertTrue(Gate::forUser($administrator)->allows('delete', $contract));
    }

    public function test_custom_role_and_navigation_depend_on_permission_not_role_name(): void
    {
        $contract = $this->contract($this->company('Custom contract role'));
        $user = $this->actingAsCustomRole([PermissionName::ContractsView->value]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('contracts.index'), false);
        $this->get(route('contracts.show', $contract))->assertOk();
        $this->assertFalse($user->hasRole('viewer'));
        $this->assertFalse($user->hasRole('administrator'));

        $this->actingAsPermissions();
        $this->get(route('home'))->assertDontSee(route('contracts.index'), false);
    }

    public static function dependencyProvider(): array
    {
        return [
            'order' => ['orders'],
            'subscription' => ['subscriptions'],
            'document' => ['contract_documents'],
            'invoice' => ['invoices'],
        ];
    }

    private function createDependency(Contract $contract, string $table): int
    {
        return match ($table) {
            'orders' => $contract->orders()->create([
                'service_type_id' => $this->serviceType('one_time'),
                'order_date' => '2026-08-01',
                'price' => '10.00',
                'payment_terms' => 14,
            ])->id,
            'subscriptions' => $contract->subscriptions()->create([
                'service_type_id' => $this->serviceType('subscription'),
                'start_date' => '2026-08-01',
                'next_billing_date' => '2026-09-01',
                'billing_period' => 'monthly',
                'amount' => '10.00',
                'payment_terms' => 14,
            ])->id,
            'contract_documents' => tap($contract->documents()->create([
                'document_type' => 'other',
                'original_name' => 'protected.pdf',
                'file_path' => 'contracts/protected.pdf',
            ]), fn (): bool => Storage::disk('local')->put('contracts/protected.pdf', 'protected'))->id,
            'invoices' => $contract->invoices()->create([
                'company_id' => $contract->company_id,
                'invoice_number' => 'BLOCK-CONTRACT-'.uniqid(),
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-15',
                'total_amount' => '0.00',
                'status' => 'draft',
            ])->id,
        };
    }

    private function serviceType(string $type): int
    {
        return DB::table('service_types')->insertGetId([
            'name' => 'Contract authorization '.$type.' '.uniqid(),
            'base_price' => '10.00',
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Company, array<string, string>} */
    private function companyWithSecrets(string $marker): array
    {
        $company = $this->company($marker.'-MINIMAL-COMPANY-NAME');
        $financialMarker = match ($marker) {
            'VIEW' => '876541.11',
            'CREATE' => '876542.22',
            'UPDATE' => '876543.33',
        };
        $secrets = [
            'voen' => substr($marker, 0, 1).'-SECRET-VOEN-9031',
            'iban' => 'AZ00'.$marker.'SECRETIBAN0000000000',
            'legal_address' => $marker.'-SECRET-LEGAL-ADDRESS',
            'actual_address' => $marker.'-SECRET-ACTUAL-ADDRESS',
            'phone' => '+99450'.match ($marker) {
                'VIEW' => '9911001',
                'CREATE' => '9911002',
                'UPDATE' => '9911003',
            },
            'email' => strtolower($marker).'-secret-company@example.test',
            'website' => 'https://'.strtolower($marker).'-secret-company.example.test',
            'comment' => $marker.'-SECRET-COMPANY-COMMENT',
            'financial_marker' => $financialMarker,
        ];

        $company->forceFill([
            'voen' => $secrets['voen'],
            'iban' => $secrets['iban'],
            'legal_address' => $secrets['legal_address'],
            'actual_address' => $secrets['actual_address'],
            'phone' => $secrets['phone'],
            'email' => $secrets['email'],
            'website' => $secrets['website'],
            'comment' => $secrets['comment'],
        ])->save();
        $company->creditBalance()->create(['amount' => $financialMarker]);

        return [$company, $secrets];
    }

    /** @param array<string, string> $secrets */
    private function assertCompanySecretsAbsent(TestResponse $response, array $secrets): void
    {
        $content = $response->getContent();

        foreach ($secrets as $field => $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $content,
                "Company {$field} leaked into the raw response."
            );
        }
    }
}
