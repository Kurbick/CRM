<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Testing\TestResponse;

class CompanyContactAuthorizationTest extends AuthorizationTestCase
{
    public function test_contact_create_and_store_require_permission_and_preserve_database(): void
    {
        $company = $this->company('Forbidden contact parent');
        $before = $company->contacts()->count();
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->get(route('companies.contacts.create', $company))->assertForbidden();
        $this->post(route('companies.contacts.store', $company), $this->contactPayload('Forbidden Create'))
            ->assertForbidden();

        $this->assertSame($before, $company->contacts()->count());
        $this->assertDatabaseMissing('company_contacts', [
            'company_id' => $company->id,
            'first_name' => 'Forbidden Create',
        ]);
    }

    public function test_contact_update_requires_permission_and_preserves_fields_and_parent(): void
    {
        $companyA = $this->company('Contact Company A');
        $companyB = $this->company('Contact Company B');
        $contact = $this->contact($companyA, 'Original Contact');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->get(route('contacts.edit', $contact))->assertForbidden();
        $this->put(route('contacts.update', $contact), [
            ...$this->contactPayload('Forbidden Updated Contact'),
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $fresh = $contact->fresh();
        $this->assertSame('Original Contact', $fresh->first_name);
        $this->assertSame('+994500000001', $fresh->phone);
        $this->assertSame('Original contact comment', $fresh->comment);
        $this->assertSame($companyA->id, $fresh->company_id);
    }

    public function test_contact_destroy_requires_permission_and_preserves_contact(): void
    {
        $company = $this->company('Delete contact parent');
        $contact = $this->contact($company, 'Forbidden Delete Contact');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $this->delete(route('contacts.destroy', $contact))->assertForbidden();

        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_store_uses_url_parent_and_redirects_to_dashboard_without_company_view(): void
    {
        $companyA = $this->company('Store Company A');
        $companyB = $this->company('Store Company B');
        $secrets = $this->addSensitiveCompanyData($companyA, 'CREATE');
        $this->actingAsPermissions([PermissionName::CompanyContactsCreate->value]);

        $createPage = $this->get(route('companies.contacts.create', $companyA))
            ->assertOk()
            ->assertSee(route('dashboard'), false);
        $this->assertMinimalCompanyDisclosure($createPage, $companyA, $secrets);
        $this->post(route('companies.contacts.store', $companyA), [
            ...$this->contactPayload('Created Contact'),
            'company_id' => $companyB->id,
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('company_contacts', [
            'company_id' => $companyA->id,
            'first_name' => 'Created Contact',
        ]);
        $this->assertDatabaseMissing('company_contacts', [
            'company_id' => $companyB->id,
            'first_name' => 'Created Contact',
        ]);
    }

    public function test_store_redirects_to_company_context_when_company_view_is_allowed(): void
    {
        $company = $this->company('Visible store company');
        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompanyContactsCreate->value,
        ]);

        $this->post(route('companies.contacts.store', $company), [
            ...$this->contactPayload('Visible Created Contact'),
            'origin' => 'company',
            'tab' => 'contacts',
        ])->assertRedirect(route('companies.show', [
            'company' => $company,
            'tab' => 'contacts',
        ]));
    }

    public function test_update_ignores_company_id_and_redirects_safely_without_company_view(): void
    {
        $companyA = $this->company('Update Company A');
        $companyB = $this->company('Update Company B');
        $secrets = $this->addSensitiveCompanyData($companyA, 'UPDATE');
        $contact = $this->contact($companyA, 'Original Contact');
        $this->actingAsPermissions([PermissionName::CompanyContactsUpdate->value]);

        $editPage = $this->get(route('contacts.edit', $contact))
            ->assertOk()
            ->assertSee(route('dashboard'), false);
        $this->assertMinimalCompanyDisclosure($editPage, $companyA, $secrets);
        $this->put(route('contacts.update', $contact), [
            ...$this->contactPayload('Allowed Updated Contact'),
            'company_id' => $companyB->id,
        ])->assertRedirect(route('dashboard'));

        $fresh = $contact->fresh();
        $this->assertSame('Allowed Updated Contact', $fresh->first_name);
        $this->assertSame('+994500000003', $fresh->phone);
        $this->assertSame('Updated contact comment', $fresh->comment);
        $this->assertSame($companyA->id, $fresh->company_id);
    }

    public function test_delete_works_without_company_view_and_redirects_to_dashboard(): void
    {
        $company = $this->company('Contact delete company');
        $contact = $this->contact($company, 'Allowed Delete Contact');
        $this->actingAsPermissions([PermissionName::CompanyContactsDelete->value]);

        $this->delete(route('contacts.destroy', $contact))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('company_contacts', ['id' => $contact->id]);
    }

    public function test_contact_ui_actions_follow_independent_permissions(): void
    {
        $company = $this->company('Contact UI company');
        $contact = $this->contact($company, 'Contact UI person');
        $base = [PermissionName::CompaniesView->value];

        $this->actingAsPermissions($base);
        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee(route('companies.contacts.create', $company), false)
            ->assertDontSee('href="'.route('contacts.edit', $contact), false)
            ->assertDontSee('action="'.route('contacts.destroy', $contact).'"', false);

        $this->actingAsPermissions([...$base, PermissionName::CompanyContactsCreate->value]);
        $this->get(route('companies.show', $company))
            ->assertSee(route('companies.contacts.create', $company), false)
            ->assertDontSee('href="'.route('contacts.edit', $contact), false);

        $this->actingAsPermissions([...$base, PermissionName::CompanyContactsUpdate->value]);
        $this->get(route('companies.show', $company))
            ->assertSee(route('contacts.edit', $contact), false)
            ->assertDontSee('action="'.route('contacts.destroy', $contact).'"', false);

        $this->actingAsPermissions([...$base, PermissionName::CompanyContactsDelete->value]);
        $this->get(route('companies.show', $company))
            ->assertSee(route('contacts.destroy', $contact), false)
            ->assertDontSee('href="'.route('contacts.edit', $contact), false);
    }

    public function test_seeded_roles_and_custom_role_follow_contact_permission_matrix(): void
    {
        $company = $this->company('Role matrix contact company');
        $contact = $this->contact($company, 'ROLE-MATRIX-ORIGINAL');
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::findByName('viewer'));
        $accountant = User::factory()->create();
        $accountant->assignRole(Role::findByName('accountant'));

        foreach (['viewer' => $viewer, 'accountant' => $accountant] as $roleName => $user) {
            $this->actingAs($user, 'web');
            $this->get(route('companies.contacts.create', $company))->assertForbidden();
            $this->post(
                route('companies.contacts.store', $company),
                $this->contactPayload('FORBIDDEN-'.strtoupper($roleName).'-CREATE')
            )->assertForbidden();
            $this->put(
                route('contacts.update', $contact),
                $this->contactPayload('FORBIDDEN-'.strtoupper($roleName).'-UPDATE')
            )->assertForbidden();
            $this->delete(route('contacts.destroy', $contact))->assertForbidden();

            $this->assertFalse($user->can(PermissionName::CompanyContactsCreate->value));
            $this->assertFalse($user->can(PermissionName::CompanyContactsUpdate->value));
            $this->assertFalse($user->can(PermissionName::CompanyContactsDelete->value));
            $this->assertDatabaseMissing('company_contacts', [
                'first_name' => 'FORBIDDEN-'.strtoupper($roleName).'-CREATE',
            ]);
            $this->assertDatabaseHas('company_contacts', [
                'id' => $contact->id,
                'first_name' => 'ROLE-MATRIX-ORIGINAL',
                'company_id' => $company->id,
            ]);
        }

        $custom = $this->actingAsCustomRole([
            PermissionName::CompanyContactsCreate->value,
        ]);
        $this->get(route('companies.contacts.create', $company))->assertOk();
        $this->assertFalse($custom->hasRole('administrator'));
        $this->assertFalse($custom->can(PermissionName::CompaniesView->value));

        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');
        $this->get(route('companies.contacts.create', $company))->assertOk();
        $this->post(
            route('companies.contacts.store', $company),
            $this->contactPayload('ADMIN-CREATED-CONTACT')
        )->assertRedirect();
        $this->put(
            route('contacts.update', $contact),
            $this->contactPayload('ADMIN-UPDATED-CONTACT')
        )->assertRedirect();

        $this->assertDatabaseHas('company_contacts', [
            'company_id' => $company->id,
            'first_name' => 'ADMIN-CREATED-CONTACT',
        ]);
        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'first_name' => 'ADMIN-UPDATED-CONTACT',
        ]);

        $this->delete(route('contacts.destroy', $contact))->assertRedirect();
        $this->assertDatabaseMissing('company_contacts', ['id' => $contact->id]);
    }

    /** @return list<string> */
    private function addSensitiveCompanyData(Company $company, string $prefix): array
    {
        $secrets = [
            "SECRET-{$prefix}-VOEN",
            "SECRET-{$prefix}-IBAN",
            "SECRET-{$prefix}-LEGAL-ADDRESS",
            "SECRET-{$prefix}-ACTUAL-ADDRESS",
            "SECRET-{$prefix}-PHONE",
            strtolower("secret-{$prefix}@company.test"),
            strtolower("https://secret-{$prefix}.company.test"),
            "SECRET-{$prefix}-COMPANY-COMMENT",
            "SECRET-{$prefix}-CONTRACT",
            '98765.43',
        ];
        $company->forceFill([
            'voen' => $secrets[0],
            'iban' => $secrets[1],
            'legal_address' => $secrets[2],
            'actual_address' => $secrets[3],
            'phone' => $secrets[4],
            'email' => $secrets[5],
            'website' => $secrets[6],
            'comment' => $secrets[7],
        ])->save();
        $contract = $this->contract($company);
        $contract->forceFill(['contract_number' => $secrets[8]])->save();
        $company->creditBalance()->create(['amount' => $secrets[9]]);

        return $secrets;
    }

    /** @param list<string> $secrets */
    private function assertMinimalCompanyDisclosure(
        TestResponse $response,
        Company $company,
        array $secrets
    ): void {
        $response->assertSee($company->name)
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('companies.show', $company).'"', false)
            ->assertDontSee('name="company_id"', false);

        foreach ($secrets as $secret) {
            $response->assertDontSee($secret, false);
        }
    }
}
