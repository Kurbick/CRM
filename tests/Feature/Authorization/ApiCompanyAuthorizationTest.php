<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DomainQueryRecorder;

class ApiCompanyAuthorizationTest extends AuthorizationTestCase
{
    private const COMPACT_KEYS = [
        'id',
        'type',
        'name',
        'short_name',
        'voen',
        'email',
        'phone',
        'website',
        'status',
        'invoice_mode',
        'created_at',
        'updated_at',
    ];

    private const DETAIL_KEYS = [
        ...self::COMPACT_KEYS,
        'bank_name',
        'iban',
        'bank_code',
        'bank_voen',
        'swift',
        'legal_address',
        'actual_address',
        'comment',
    ];

    public function test_guest_is_stopped_before_company_access(): void
    {
        $this->getJson(route('api.companies.index'))->assertUnauthorized();
    }

    public function test_inactive_user_is_stopped_before_company_access(): void
    {
        $inactive = User::factory()->inactive()->create();
        $inactive->givePermissionTo(PermissionName::CompaniesView->value);
        $this->actingAs($inactive, 'web');
        $this->getJson(route('api.companies.index'))
            ->assertForbidden()
            ->assertJson(['message' => 'Учётная запись отключена.']);
    }

    public function test_temporary_password_user_is_stopped_before_company_access(): void
    {
        $temporary = User::factory()->requiringPasswordChange()->create();
        $temporary->givePermissionTo(PermissionName::CompaniesView->value);
        $this->actingAs($temporary, 'web');
        $this->getJson(route('api.companies.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_missing_and_wrong_permissions_fail_before_company_binding_or_queries(): void
    {
        $company = $this->company('API permission target');

        foreach ([[], [PermissionName::CompaniesUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);

            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.companies.show', $company)),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.companies.show', ['company' => $company->id + 1_000_000])),
            );

            $existing['result']->assertForbidden();
            $missing['result']->assertForbidden();
            $this->assertSame($existing['result']->status(), $missing['result']->status());
            $this->assertSame([], $existing['records']);
            $this->assertSame([], $missing['records']);
        }
    }

    public function test_exact_permission_and_custom_role_reach_company_policy(): void
    {
        $company = $this->company('API policy target');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'view' && ($arguments[0] ?? null) instanceof Company) {
                $abilities[] = $ability;
            }
        });

        $this->actingAsPermissions([PermissionName::CompaniesView->value]);
        $this->getJson(route('api.companies.show', $company))->assertOk();
        $this->assertContains('view', $abilities);

        $this->actingAsCustomRole([PermissionName::CompaniesView->value]);
        $this->getJson(route('api.companies.index'))->assertOk();
    }

    public function test_administrator_uses_existing_gate_before_for_company_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user, 'web');

        $this->getJson(route('api.companies.index'))->assertOk();
    }

    public function test_company_index_has_compact_projection_stable_order_and_one_domain_query(): void
    {
        $first = Company::query()->create($this->companyPayload('INDEX-FIRST-MARKER'));
        $second = Company::query()->create($this->companyPayload('INDEX-SECOND-MARKER'));
        $contact = $this->contact($first, 'INDEX-CONTACT-SECRET');
        $contract = $this->contract($first);
        $invoice = $this->invoiceFor($first, 'INDEX-INVOICE-SECRET');
        $this->paymentFor($invoice, 'INDEX-PAYMENT-SECRET');
        $first->creditBalance()->create(['amount' => '98765.43']);
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'viewAny' && ($arguments[0] ?? null) === Company::class) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.index')),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json('data');
        $this->assertSame(2, $capture['result']->json('meta.total'));
        $this->assertIsArray($payload);
        $this->assertSame([$first->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[0]));
        $this->assertContains('viewAny', $abilities);
        $this->assertSame(['companies'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));

        foreach ([
            $first->bank_name,
            $first->iban,
            $first->legal_address,
            $first->comment,
            $contact->first_name,
            $contract->contract_number,
            $invoice->invoice_number,
            'INDEX-PAYMENT-SECRET',
            '98765.43',
        ] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_company_index_has_bounded_pagination_and_normalizes_page_size(): void
    {
        $companies = [];
        foreach (range(1, 26) as $index) {
            $companies[] = Company::query()->create($this->companyPayload("PAGED-COMPANY-{$index}"));
        }
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $response = $this->getJson(route('api.companies.index').'?page=2&per_page=10');
        $response->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->assertSame($companies[10]->id, $response->json('data.0.id'));
        $this->assertSame($companies[19]->id, $response->json('data.9.id'));
        $this->assertStringContainsString('per_page=10', $response->json('links.next'));

        $normalized = $this->getJson(route('api.companies.index').'?page=0&per_page=1000');
        $normalized->assertOk()->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(26, 'data');
    }

    public function test_company_show_has_detail_projection_without_relation_queries(): void
    {
        $company = Company::query()->create($this->companyPayload('DETAIL-COMPANY'));
        $contact = $this->contact($company, 'DETAIL-CONTACT-SECRET');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract, ['title' => 'DETAIL-ORDER-SECRET']);
        $subscription = $this->subjectSubscription($contract, ['title' => 'DETAIL-SUBSCRIPTION-SECRET']);
        $invoice = $this->invoiceFor($company, 'DETAIL-INVOICE-SECRET');
        $this->paymentFor($invoice, 'DETAIL-PAYMENT-SECRET');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.show', $company)),
        );

        $capture['result']->assertOk();
        $this->assertSame(self::DETAIL_KEYS, array_keys($capture['result']->json()));
        $capture['result']
            ->assertJsonPath('bank_name', $company->bank_name)
            ->assertJsonPath('iban', $company->iban);
        $this->assertSame(['companies'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));

        foreach ([
            $contact->first_name,
            $contract->contract_number,
            $order->title,
            $subscription->title,
            $invoice->invoice_number,
            'DETAIL-PAYMENT-SECRET',
        ] as $marker) {
            $capture['result']->assertDontSee($marker);
        }

        $this->getJson(route('api.companies.show', ['company' => $company->id + 1_000_000]))
            ->assertNotFound();
    }

    public function test_company_store_preserves_accepted_fields_and_ignores_server_managed_input(): void
    {
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === Company::class) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesCreate->value]);
        $payload = $this->companyPayload('STORE-COMPANY');
        $payload += [
            'id' => 9_000_000,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'company_id' => 8_000_000,
            'contacts' => [['first_name' => 'SHOULD-NOT-EXIST']],
        ];

        $response = $this->postJson(route('api.companies.store'), $payload)
            ->assertCreated();

        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $this->assertContains('create', $abilities);
        $this->assertNotSame(9_000_000, $response->json('id'));
        $this->assertNotSame('2000-01-01T00:00:00.000000Z', $response->json('created_at'));
        $this->assertDatabaseHas('companies', [
            'id' => $response->json('id'),
            'name' => 'STORE-COMPANY',
            'status' => $payload['status'],
            'invoice_mode' => $payload['invoice_mode'],
        ]);
        $this->assertDatabaseMissing('company_contacts', ['first_name' => 'SHOULD-NOT-EXIST']);
    }

    public function test_company_patch_updates_only_validated_fields_and_keeps_lifecycle_fields_supported(): void
    {
        $company = Company::query()->create($this->companyPayload('PATCH-ORIGINAL'));
        $originalIban = $company->iban;
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'update' && ($arguments[0] ?? null) instanceof Company) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesUpdate->value]);

        $response = $this->patchJson(route('api.companies.update', $company), [
            'name' => 'PATCH-UPDATED',
            'status' => 'suspended',
            'invoice_mode' => 'consolidated',
            'id' => 9_000_001,
            'created_at' => '2000-01-01 00:00:00',
            'contract_id' => 8_000_001,
        ])->assertOk();

        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $this->assertContains('update', $abilities);
        $company->refresh();
        $this->assertSame('PATCH-UPDATED', $company->name);
        $this->assertSame('suspended', $company->status);
        $this->assertSame('consolidated', $company->invoice_mode);
        $this->assertSame($originalIban, $company->iban);
        $this->assertNotSame(9_000_001, $company->id);
        $this->assertNotSame('2000-01-01 00:00:00', $company->getRawOriginal('created_at'));
    }

    public function test_empty_company_is_deleted_and_repeated_request_returns_not_found(): void
    {
        $company = $this->company('DELETE-EMPTY');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'delete' && ($arguments[0] ?? null) instanceof Company) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesDelete->value]);

        $this->deleteJson(route('api.companies.destroy', $company))
            ->assertOk()
            ->assertExactJson(['message' => 'Компания удалена']);
        $this->assertContains('delete', $abilities);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);

        $this->deleteJson(route('api.companies.destroy', $company))->assertNotFound();
    }

    #[DataProvider('blockingDependencyProvider')]
    public function test_company_dependencies_return_conflict_without_partial_delete(string $dependency): void
    {
        $company = $this->company('DELETE-BLOCKED-'.$dependency);
        $contact = null;

        if (in_array($dependency, ['contact', 'multiple'], true)) {
            $contact = $this->contact($company, 'DELETE-PRESERVED-'.$dependency);
        }
        if (in_array($dependency, ['contract', 'multiple'], true)) {
            $this->contract($company);
        }
        if (in_array($dependency, ['invoice', 'payment'], true)) {
            $invoice = $this->invoiceFor($company, 'DELETE-'.$dependency);
            if ($dependency === 'payment') {
                $this->paymentFor($invoice, 'DELETE-PAYMENT');
            }
        }
        if (in_array($dependency, ['credit_balance', 'multiple'], true)) {
            $company->creditBalance()->create(['amount' => '10.00']);
        }

        $this->actingAsPermissions([PermissionName::CompaniesDelete->value]);

        $this->deleteJson(route('api.companies.destroy', $company))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Невозможно удалить компанию — есть связанные данные',
            ]);

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        if ($contact !== null) {
            $this->assertDatabaseHas('company_contacts', [
                'id' => $contact->id,
                'company_id' => $company->id,
            ]);
        }
    }

    public function test_non_business_delete_exception_is_not_converted_to_conflict(): void
    {
        $company = $this->company('DELETE-RUNTIME-ERROR');
        $this->actingAsPermissions([PermissionName::CompaniesDelete->value]);
        Company::deleting(function (Company $deletingCompany) use ($company): void {
            if ($deletingCompany->is($company)) {
                throw new RuntimeException('DELETE-RUNTIME-MARKER');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.companies.destroy', $company));
            $this->fail('The runtime exception was converted into an HTTP response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DELETE-RUNTIME-MARKER', $exception->getMessage());
        }

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public static function blockingDependencyProvider(): array
    {
        return [
            'contact' => ['contact'],
            'contract' => ['contract'],
            'invoice' => ['invoice'],
            'payment' => ['payment'],
            'credit balance' => ['credit_balance'],
            'multiple' => ['multiple'],
        ];
    }

    private function invoiceFor(Company $company, string $number): Invoice
    {
        return Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => null,
            'invoice_number' => $number.'-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
    }

    private function paymentFor(Invoice $invoice, string $comment): Payment
    {
        $id = Payment::query()->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-10',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'status' => 'pending',
            'comment' => $comment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Payment::query()->findOrFail($id);
    }
}
