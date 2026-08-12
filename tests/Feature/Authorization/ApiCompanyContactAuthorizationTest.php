<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainQueryRecorder;

class ApiCompanyContactAuthorizationTest extends AuthorizationTestCase
{
    private const CONTACT_KEYS = [
        'id',
        'company_id',
        'first_name',
        'last_name',
        'position',
        'phone',
        'email',
        'role',
        'comment',
        'created_at',
        'updated_at',
    ];

    public function test_guest_is_stopped_before_contact_access(): void
    {
        $company = $this->company('CONTACT-AUTH-COMPANY');
        $contact = $this->contact($company, 'CONTACT-AUTH-TARGET');

        $this->getJson(route('api.contacts.show', $contact))->assertUnauthorized();
    }

    public function test_inactive_user_is_stopped_before_contact_access(): void
    {
        $company = $this->company('CONTACT-INACTIVE-COMPANY');
        $contact = $this->contact($company, 'CONTACT-INACTIVE-TARGET');
        $inactive = User::factory()->inactive()->create();
        $inactive->givePermissionTo(PermissionName::CompaniesView->value);
        $this->actingAs($inactive, 'web');
        $this->getJson(route('api.contacts.show', $contact))->assertForbidden();
    }

    public function test_temporary_password_user_is_stopped_before_contact_access(): void
    {
        $company = $this->company('CONTACT-TEMPORARY-COMPANY');
        $contact = $this->contact($company, 'CONTACT-TEMPORARY-TARGET');
        $temporary = User::factory()->requiringPasswordChange()->create();
        $temporary->givePermissionTo(PermissionName::CompaniesView->value);
        $this->actingAs($temporary, 'web');
        $this->getJson(route('api.contacts.show', $contact))
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_missing_and_wrong_permissions_fail_before_contact_binding_or_queries(): void
    {
        $company = $this->company('CONTACT-PERMISSION-COMPANY');
        $contact = $this->contact($company, 'CONTACT-PERMISSION-TARGET');

        foreach ([[], [PermissionName::CompanyContactsUpdate->value]] as $permissions) {
            $this->actingAsPermissions($permissions);
            $existing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.contacts.show', $contact)),
            );
            $missing = (new DomainQueryRecorder)->capture(
                fn () => $this->getJson(route('api.contacts.show', ['contact' => $contact->id + 1_000_000])),
            );

            $existing['result']->assertForbidden();
            $missing['result']->assertForbidden();
            $this->assertSame($existing['result']->status(), $missing['result']->status());
            $this->assertSame([], $existing['records']);
            $this->assertSame([], $missing['records']);
        }
    }

    public function test_contact_show_binds_intended_contact_invokes_policy_and_returns_safe_company_summary(): void
    {
        $company = Company::query()->create([
            ...$this->companyPayload('CONTACT-SHOW-COMPANY'),
            'short_name' => 'Safe short name',
        ]);
        $contact = $this->contact($company, 'CONTACT-SHOW-TARGET');
        $other = $this->contact($company, 'CONTACT-SHOW-OTHER');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'view' && ($arguments[0] ?? null) instanceof CompanyContact) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.contacts.show', $contact)),
        );

        $capture['result']->assertOk();
        $this->assertSame([
            ...self::CONTACT_KEYS,
            'company',
        ], array_keys($capture['result']->json()));
        $capture['result']
            ->assertJsonPath('id', $contact->id)
            ->assertJsonPath('first_name', 'CONTACT-SHOW-TARGET')
            ->assertJsonPath('company', [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => 'Safe short name',
            ]);
        $this->assertContains('view', $abilities);
        $this->assertSame(
            ['company_contacts', 'companies'],
            DomainQueryRecorder::tables($capture['records'])
        );
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));

        foreach ([
            $company->voen,
            $company->bank_name,
            $company->iban,
            $company->legal_address,
            $company->phone,
            $company->email,
            $company->website,
            $company->status,
            $company->comment,
            $other->first_name,
        ] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_contact_show_missing_id_returns_not_found_after_permission(): void
    {
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->getJson(route('api.contacts.show', ['contact' => 1_000_000]))
            ->assertNotFound();
    }

    public function test_contact_update_missing_id_returns_not_found_after_permission(): void
    {
        $this->actingAsPermissions([PermissionName::CompanyContactsUpdate->value]);

        $this->patchJson(route('api.contacts.update', ['contact' => 1_000_000]), [
            'first_name' => 'MISSING-CONTACT-SHOULD-NOT-EXIST',
        ])->assertNotFound();

        $this->assertDatabaseMissing('company_contacts', [
            'first_name' => 'MISSING-CONTACT-SHOULD-NOT-EXIST',
        ]);
    }

    public function test_nested_index_is_parent_scoped_projected_and_constant_query_count(): void
    {
        $company = Company::query()->create($this->companyPayload('CONTACT-INDEX-A'));
        $otherCompany = Company::query()->create($this->companyPayload('CONTACT-INDEX-B'));
        $first = $this->contact($company, 'CONTACT-INDEX-FIRST');
        $second = $this->contact($company, 'CONTACT-INDEX-SECOND');
        $other = $this->contact($otherCompany, 'CONTACT-INDEX-OTHER-SECRET');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'view' && ($arguments[0] ?? null) instanceof Company) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.contacts.index', $company)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame([$first->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::CONTACT_KEYS, array_keys($payload[0]));
        $this->assertContains('view', $abilities);
        $this->assertSame(
            ['companies', 'company_contacts'],
            DomainQueryRecorder::tables($capture['records'])
        );
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));
        $capture['result']
            ->assertDontSee($other->first_name)
            ->assertDontSee($company->iban)
            ->assertJsonMissingPath('0.company');
    }

    public function test_nested_store_uses_bound_parent_ignores_payload_company_id_and_returns_safe_projection(): void
    {
        $company = Company::query()->create($this->companyPayload('CONTACT-STORE-A'));
        $otherCompany = Company::query()->create($this->companyPayload('CONTACT-STORE-B'));
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'create' && ($arguments[0] ?? null) === CompanyContact::class) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompanyContactsCreate->value]);

        $response = $this->postJson(route('api.companies.contacts.store', $company), [
            ...$this->contactPayload('CONTACT-STORE-TARGET'),
            'company_id' => $otherCompany->id,
        ])->assertCreated();

        $contact = CompanyContact::query()->where('first_name', 'CONTACT-STORE-TARGET')->sole();
        $this->assertSame($company->id, $contact->company_id);
        $this->assertContains('create', $abilities);
        $this->assertSame([
            ...self::CONTACT_KEYS,
            'company',
        ], array_keys($response->json()));
        $response->assertJsonPath('company', [
            'id' => $company->id,
            'name' => $company->name,
            'short_name' => $company->short_name,
        ]);
        $response->assertDontSee($company->iban);
        $response->assertDontSee($otherCompany->name);
    }

    public function test_nested_store_wrong_permission_fails_before_parent_binding_and_insert(): void
    {
        $company = $this->company('CONTACT-STORE-DENIED');
        $this->actingAsPermissions([PermissionName::CompanyContactsUpdate->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(
                route('api.companies.contacts.store', $company),
                $this->contactPayload('CONTACT-SHOULD-NOT-EXIST')
            ),
        );

        $capture['result']->assertForbidden();
        $this->assertSame([], $capture['records']);
        $this->assertDatabaseMissing('company_contacts', ['first_name' => 'CONTACT-SHOULD-NOT-EXIST']);
    }

    public function test_contact_update_changes_only_intended_contact_and_cannot_move_company(): void
    {
        $company = $this->company('CONTACT-UPDATE-A');
        $otherCompany = $this->company('CONTACT-UPDATE-B');
        $target = $this->contact($company, 'CONTACT-UPDATE-TARGET');
        $other = $this->contact($company, 'CONTACT-UPDATE-OTHER');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'update' && ($arguments[0] ?? null) instanceof CompanyContact) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompanyContactsUpdate->value]);

        $response = $this->patchJson(route('api.contacts.update', $target), [
            'first_name' => 'CONTACT-UPDATE-CHANGED',
            'company_id' => $otherCompany->id,
            'id' => 9_000_002,
            'created_at' => '2000-01-01 00:00:00',
        ])->assertOk();

        $target->refresh();
        $other->refresh();
        $this->assertSame('CONTACT-UPDATE-CHANGED', $target->first_name);
        $this->assertSame($company->id, $target->company_id);
        $this->assertSame('CONTACT-UPDATE-OTHER', $other->first_name);
        $this->assertContains('update', $abilities);
        $this->assertSame([
            ...self::CONTACT_KEYS,
            'company',
        ], array_keys($response->json()));
        $response->assertJsonPath('company.id', $company->id);
        $response->assertDontSee($company->iban);
    }

    public function test_contact_destroy_deletes_only_intended_contact_and_preserves_company(): void
    {
        $company = $this->company('CONTACT-DELETE-COMPANY');
        $target = $this->contact($company, 'CONTACT-DELETE-TARGET');
        $other = $this->contact($company, 'CONTACT-DELETE-OTHER');
        $abilities = [];
        Gate::after(function ($user, string $ability, $result, array $arguments) use (&$abilities): void {
            if ($ability === 'delete' && ($arguments[0] ?? null) instanceof CompanyContact) {
                $abilities[] = $ability;
            }
        });
        $this->actingAsPermissions([PermissionName::CompanyContactsDelete->value]);

        $this->deleteJson(route('api.contacts.destroy', $target))
            ->assertOk()
            ->assertExactJson(['message' => 'Контакт удалён']);

        $this->assertContains('delete', $abilities);
        $this->assertDatabaseMissing('company_contacts', ['id' => $target->id]);
        $this->assertDatabaseHas('company_contacts', ['id' => $other->id]);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->deleteJson(route('api.contacts.destroy', $target))->assertNotFound();
    }

    public function test_contact_mutation_wrong_permissions_do_not_change_database(): void
    {
        $company = $this->company('CONTACT-MUTATION-DENIED');
        $contact = $this->contact($company, 'CONTACT-MUTATION-ORIGINAL');
        $this->actingAsPermissions([PermissionName::CompanyContactsCreate->value]);

        $this->patchJson(route('api.contacts.update', $contact), [
            'first_name' => 'CONTACT-MUTATION-FORBIDDEN',
        ])->assertForbidden();
        $this->deleteJson(route('api.contacts.destroy', $contact))->assertForbidden();

        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
            'first_name' => 'CONTACT-MUTATION-ORIGINAL',
        ]);
    }

    public function test_custom_role_and_administrator_use_standard_gate_mechanism(): void
    {
        $company = $this->company('CONTACT-ROLE-COMPANY');
        $contact = $this->contact($company, 'CONTACT-ROLE-TARGET');

        $customUser = $this->actingAsCustomRole([PermissionName::CompaniesView->value]);
        $this->getJson(route('api.contacts.show', $contact))->assertOk();
        $this->assertFalse($customUser->hasRole('administrator'));

        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');
        $this->getJson(route('api.contacts.show', $contact))->assertOk();
    }
}
