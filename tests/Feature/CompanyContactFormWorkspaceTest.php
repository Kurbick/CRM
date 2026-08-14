<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\FinancialTestCase as TestCase;

class CompanyContactFormWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompanyContactsCreate->value,
            PermissionName::CompanyContactsUpdate->value,
        ]);
    }

    public function test_create_form_keeps_company_context_old_input_and_contact_fields(): void
    {
        $company = Company::query()->create(['name' => 'Contact Workspace Company', 'status' => 'active']);
        $createUrl = route('companies.contacts.create', [
            'company' => $company,
            'origin' => 'company',
            'tab' => 'contacts',
        ]);

        $this->from($createUrl)->post(route('companies.contacts.store', $company), [
            'first_name' => 'Old Contact',
            'role' => 'other',
            'position' => 'Old Position',
            'phone' => '+994500000001',
            'email' => 'not-an-email',
            'comment' => 'Old comment',
            'origin' => 'company',
            'tab' => 'contacts',
        ])->assertRedirect($createUrl)->assertSessionHasErrors('email');

        $this->get($createUrl)
            ->assertOk()
            ->assertSee('data-testid="contact-form-workspace"', false)
            ->assertSee('action="'.route('companies.contacts.store', $company).'"', false)
            ->assertSee('Contact Workspace Company')
            ->assertSee('name="origin" value="company"', false)
            ->assertSee('name="tab" value="contacts"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="role"', false)
            ->assertSee('name="position"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="comment"', false)
            ->assertSee('value="Old Contact"', false)
            ->assertSee('value="Old Position"', false)
            ->assertSee('value="+994500000001"', false)
            ->assertSee('value="not-an-email"', false)
            ->assertSee('Old comment');
    }

    public function test_edit_form_uses_update_action_and_only_shows_delete_when_authorised(): void
    {
        $company = Company::query()->create(['name' => 'Edit Contact Company', 'status' => 'active']);
        $contact = $company->contacts()->create([
            'first_name' => 'Edit',
            'last_name' => 'Contact',
            'role' => 'other',
            'position' => 'Coordinator',
            'phone' => '+994500000002',
            'email' => 'edit@example.test',
            'comment' => 'Saved comment',
        ]);

        $this->get(route('contacts.edit', $contact))
            ->assertOk()
            ->assertSee('data-testid="contact-form-workspace"', false)
            ->assertSee('action="'.route('contacts.update', $contact).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="Edit"', false)
            ->assertSee('value="Coordinator"', false)
            ->assertSee('value="edit@example.test"', false)
            ->assertSee('Saved comment')
            ->assertDontSee('value="DELETE"', false)
            ->assertDontSee('Удалить контакт');

        $deleteUser = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $deleteUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::CompanyContactsUpdate->value,
            PermissionName::CompanyContactsDelete->value,
        ]);
        $this->assertTrue($deleteUser->can('delete', $contact));

        $this->actingAs($deleteUser, 'web')->get(route('contacts.edit', $contact))
            ->assertOk()
            ->assertSee('value="DELETE"', false)
            ->assertSee('Удалить контакт');
    }
}
