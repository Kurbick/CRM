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

    public function test_contract_show_preserves_workspace_identity_lifecycle_and_operational_data(): void
    {
        $company = $this->company('SkyCell Workspace');
        $contract = $this->contract($company);
        $contract->forceFill([
            'contract_number' => 'CTR-2026-001',
            'start_date' => '2026-08-01',
            'end_date' => '2026-11-01',
            'comment' => 'Рабочий комментарий договора',
        ])->save();
        $order = $this->subjectOrder($contract, [
            'title' => 'Настройка сети',
            'order_date' => '2026-08-02',
            'price' => '1250.50',
        ]);
        $subscription = $this->subjectSubscription($contract, [
            'title' => 'Поддержка сети',
            'start_date' => '2026-08-03',
            'amount' => '300.00',
        ]);
        $document = $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'CTR-2026-001-signed.pdf',
            'file_path' => "contract-documents/{$contract->id}/signed.pdf",
            'file_size' => 2048,
            'comment' => 'Подписанная версия',
        ]);

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractSubjectsUpdate->value,
            PermissionName::ContractSubjectsDelete->value,
            PermissionName::ContractDocumentsUpload->value,
            PermissionName::ContractDocumentsDownload->value,
            PermissionName::ContractDocumentsDelete->value,
        ]);

        $response = $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('data-testid="contract-entity-header"', false)
            ->assertSee('data-testid="contract-lifecycle"', false)
            ->assertSee('data-testid="contract-lifecycle-status"', false)
            ->assertSee('data-testid="contract-workspace"', false)
            ->assertSee('data-layout="full"', false)
            ->assertDontSee('data-testid="contract-context"', false)
            ->assertSee('CTR-2026-001')
            ->assertSee(route('companies.show', $company), false)
            ->assertSee('SkyCell Workspace')
            ->assertSee('01/08/2026')
            ->assertSee('01/11/2026')
            ->assertSee('Дата начала')
            ->assertSee('Дата окончания')
            ->assertSee('Активен')
            ->assertDontSee('Информация')
            ->assertDontSee('Реквизиты договора')
            ->assertSee('Рабочий комментарий договора')
            ->assertSee('data-testid="contract-comment"', false)
            ->assertSee('Разовых: 1')
            ->assertSee('Подписок: 1')
            ->assertSee('Разовая услуга')
            ->assertSee('Подписка')
            ->assertSee($order->title)
            ->assertSee($subscription->title)
            ->assertSee('1,250.50 ₼')
            ->assertSee(route('contracts.subjects.create', $contract), false)
            ->assertSee(route('orders.edit', $order), false)
            ->assertSee(route('subscriptions.edit', $subscription), false)
            ->assertSee('action="'.route('orders.destroy', $order).'"', false)
            ->assertSee('action="'.route('subscriptions.destroy', $subscription).'"', false)
            ->assertSee('CTR-2026-001-signed.pdf')
            ->assertSee('Подписанная версия')
            ->assertSee(route('contract-documents.download', $document), false)
            ->assertSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertSee('aria-label="Редактировать предмет договора"', false)
            ->assertSee('aria-label="Удалить предмет договора"', false)
            ->assertSee('aria-label="Скачать документ"', false)
            ->assertSee('aria-label="Удалить документ"', false);

        $this->assertSame(1, substr_count($response->getContent(), '01/08/2026'));
        $this->assertSame(1, substr_count($response->getContent(), '01/11/2026'));
        $this->assertSame(1, substr_count($response->getContent(), 'SkyCell Workspace'));
        $this->assertSame(1, substr_count($response->getContent(), route('companies.show', $company)));

        $documentSection = substr(
            $response->getContent(),
            strpos($response->getContent(), '<section data-testid="contract-documents"')
        );
        $documentSection = substr($documentSection, 0, strpos($documentSection, '</section>'));
        $this->assertStringContainsString('class="crm-table-icon-action crm-table-icon-action-primary"', $documentSection);
        $this->assertStringContainsString('class="crm-table-icon-action crm-table-icon-action-danger"', $documentSection);
        $this->assertStringContainsString('stroke="currentColor"', $documentSection);
        $this->assertStringContainsString('<path d="M4 7h16" />', $documentSection);
    }

    public function test_contract_show_uses_indefinite_and_compact_empty_state_language(): void
    {
        $company = $this->company('Indefinite Workspace');
        $contract = $this->contract($company);
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractDocumentsDownload->value,
        ]);

        $response = $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSee('Бессрочный')
            ->assertSee('Indefinite Workspace')
            ->assertSee('data-layout="full"', false)
            ->assertDontSee('data-testid="contract-comment"', false)
            ->assertDontSee('Информация')
            ->assertDontSee('data-testid="contract-context"', false)
            ->assertDontSee('data-testid="contract-context-comment"', false)
            ->assertSee('Предметы договора пока не добавлены.')
            ->assertSee('Документы пока не добавлены.')
            ->assertSee('+ Добавить')
            ->assertDontSee('Предмет договора пока не добавлен');

        $this->assertSame(1, substr_count($response->getContent(), $company->name));
    }

    public function test_contract_subject_table_uses_one_full_width_system_with_or_without_comment(): void
    {
        $company = $this->company('Subject table layouts');
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsUpdate->value,
        ]);

        foreach ([
            'with_comment' => 'Комментарий в рабочем потоке договора',
            'without_comment' => null,
        ] as $comment) {
            $contract = $this->contract($company);
            $contract->forceFill(['comment' => $comment])->save();
            $order = $this->subjectOrder($contract, [
                'title' => 'Agentliyin üç veb-saytının hostinqi və texniki dəstəyi',
                'order_date' => '2026-01-01',
                'price' => '600.00',
            ]);
            $subscription = $this->subjectSubscription($contract, [
                'title' => 'Aylıq monitorinq və texniki dəstək',
                'start_date' => '2026-01-01',
                'billing_period' => 'monthly',
                'amount' => '600.00',
            ]);

            $response = $this->get(route('contracts.show', $contract))
                ->assertOk()
                ->assertSee('data-layout="full"', false)
                ->assertSee('data-testid="contract-subjects-table"', false)
                ->assertSee('Разовая услуга')
                ->assertSee('Подписка')
                ->assertSee($order->title)
                ->assertSee($subscription->title)
                ->assertSee('01/01/2026')
                ->assertSee('Ежемесячно')
                ->assertSee('600.00 ₼')
                ->assertSee(route('orders.edit', $order), false)
                ->assertSee(route('subscriptions.edit', $subscription), false);

            if ($comment) {
                $response->assertSee('data-testid="contract-comment"', false)
                    ->assertSee($comment);
            } else {
                $response->assertDontSee('data-testid="contract-comment"', false);
            }

            $table = substr(
                $response->getContent(),
                strpos($response->getContent(), '<table data-testid="contract-subjects-table"')
            );
            $table = substr($table, 0, strpos($table, '</table>'));

            $this->assertStringContainsString('class="crm-table w-full table-fixed"', $table);
            $this->assertStringContainsString('<col data-column="type" class="w-[9%]">', $table);
            $this->assertStringContainsString('<col data-column="name" class="w-[39%]">', $table);
            $this->assertStringContainsString('<col data-column="date" class="w-[11%]">', $table);
            $this->assertStringContainsString('<col data-column="period" class="w-[10%]">', $table);
            $this->assertStringContainsString('<col data-column="amount" class="w-[10%]">', $table);
            $this->assertStringContainsString('<col data-column="status" class="w-[11%]">', $table);
            $this->assertStringContainsString('<col data-column="actions" class="w-[10%]">', $table);
            $this->assertStringContainsString('<td class="crm-table-primary">', $table);
            $this->assertStringContainsString('<td class="crm-table-actions">', $table);
        }
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

    public function test_web_store_rejects_removed_signed_document_field(): void
    {
        $company = $this->company('Web stale store field');
        $payload = [
            ...$this->contractPayload($company, 'WEB-STALE-STORE'),
            'signed_document' => 'legacy/public/path.pdf',
        ];
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->post(route('contracts.store'), $payload)
            ->assertSessionHasErrors('signed_document');

        $this->assertDatabaseMissing('contracts', [
            'contract_number' => 'WEB-STALE-STORE',
        ]);
    }

    public function test_web_update_rejects_removed_signed_document_without_partial_mutation(): void
    {
        $contract = $this->contract($this->company('Web stale update field'));
        $before = (array) DB::table('contracts')->where('id', $contract->id)->first();
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $this->put(route('contracts.update', $contract), [
            'contract_number' => $contract->contract_number,
            'start_date' => $contract->start_date->toDateString(),
            'status' => $contract->status,
            'comment' => 'Must not be saved',
            'signed_document' => 'legacy/public/path.pdf',
        ])->assertSessionHasErrors('signed_document');

        $this->assertSame(
            $before,
            (array) DB::table('contracts')->where('id', $contract->id)->first(),
        );
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
            PermissionName::ContractDocumentsDownload->value,
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
            PermissionName::ContractDocumentsDownload->value,
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
            PermissionName::ContractDocumentsDownload->value,
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
            'subscriptions' => $contract->subscriptions()->forceCreate([
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
