<?php

namespace Tests\Feature;

use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\UpdateContact;
use App\Models\CompanyActivityEvent;
use App\Models\CompanyContact;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivityVisibilityScope;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContactActivityProducerTest extends AuthorizationTestCase
{
    public function test_web_and_api_contact_mutations_record_one_event_with_actor_and_snapshot(): void
    {
        $company = $this->company('Contact Activity Web API Company');
        $actor = $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompanyContactsCreate->value,
            PermissionName::CompanyContactsUpdate->value,
            PermissionName::CompanyContactsDelete->value,
        ]);

        $this->post(route('companies.contacts.store', $company), [
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'position' => 'Директор',
            'phone' => '+994500000001',
            'email' => 'ivan@example.test',
            'role' => 'director',
        ])->assertRedirect();

        $contact = CompanyContact::query()->where('company_id', $company->id)->sole();
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::ContactCreated->value,
            'category' => CompanyActivityCategory::Contacts->value,
            'visibility_scope' => CompanyActivityVisibilityScope::Contacts->value,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'metadata->contact_name' => 'Иван Иванов',
            'metadata->position' => 'Директор',
        ]);

        $this->patchJson(route('api.contacts.update', $contact), [
            'first_name' => 'Пётр',
            'last_name' => 'Петров',
            'position' => 'Бухгалтер',
            'phone' => '+994500000002',
            'email' => 'petr@example.test',
        ])->assertOk();

        $this->assertSame(2, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::ContactUpdated->value,
            'metadata->contact_name' => 'Пётр Петров',
            'metadata->position' => 'Бухгалтер',
        ]);

        $this->delete(route('contacts.destroy', $contact))->assertRedirect();

        $this->assertSame(3, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => CompanyActivityEventType::ContactDeleted->value,
            'subject_id' => $contact->id,
            'metadata->contact_name' => 'Пётр Петров',
            'metadata->phone' => '+994500000002',
        ]);
    }

    public function test_no_op_contact_update_records_no_event(): void
    {
        $company = $this->company('Contact Activity No Op Company');
        $contact = $company->contacts()->create([
            'first_name' => 'No',
            'last_name' => 'Op',
            'position' => 'Manager',
            'phone' => '+994500000003',
            'email' => 'no-op@example.test',
            'role' => 'manager',
        ]);

        app(UpdateContact::class)->execute($contact, [
            'first_name' => 'No',
            'last_name' => 'Op',
            'position' => 'Manager',
            'phone' => '+994500000003',
            'email' => 'no-op@example.test',
            'role' => 'manager',
        ]);

        $this->assertSame(0, $this->activityCount($company));
    }

    public function test_contact_events_are_filterable_as_contacts_and_use_company_visibility(): void
    {
        $company = $this->company('Contact Activity Filter Company');
        app(CreateContact::class)->execute($company, [
            'first_name' => 'Filter',
            'last_name' => 'Contact',
        ]);

        $event = CompanyActivityEvent::query()->where('company_id', $company->id)->sole();
        $this->assertSame(CompanyActivityCategory::Contacts->value, $event->category);
        $this->assertSame(CompanyActivityVisibilityScope::Contacts->value, $event->visibility_scope);
    }

    public function test_company_field_and_status_edits_create_no_company_activity(): void
    {
        $company = $this->company('Company Activity Noise Company');
        $this->actingAsPermissions([PermissionName::CompaniesUpdate->value]);

        $this->patchJson(route('api.companies.update', $company), [
            'phone' => '+994500000004',
        ])->assertOk();

        $this->put(route('companies.update', $company), [
            'type' => 'company',
            'name' => 'Company Activity Noise Company',
            'status' => 'suspended',
        ])->assertRedirect();

        $this->assertSame('suspended', $company->fresh()->status);
        $this->assertSame(0, $this->activityCount($company));
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'company.status_changed',
        ]);
    }

    public function test_denied_or_invalid_contact_mutations_create_no_events(): void
    {
        $company = $this->company('Contact Activity Denied Company');
        $contact = $company->contacts()->create(['first_name' => 'Protected']);
        $this->actingAsPermissions([]);

        $this->post(route('companies.contacts.store', $company), [
            'first_name' => 'Denied',
        ])->assertForbidden();
        $this->patchJson(route('api.contacts.update', $contact), [
            'first_name' => 'Denied',
        ])->assertForbidden();
        $this->delete(route('contacts.destroy', $contact))->assertForbidden();

        $this->assertSame(0, $this->activityCount($company));

        $this->actingAsPermissions([PermissionName::CompanyContactsCreate->value]);
        $this->post(route('companies.contacts.store', $company), [
            'first_name' => '',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['first_name', 'email']);

        $this->assertSame(0, $this->activityCount($company));
    }

    public function test_recorder_failure_rolls_back_create_update_and_delete(): void
    {
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        $exception = new RuntimeException('contact activity recorder failed');

        $company = $this->company('Contact Activity Create Rollback');
        $this->listenForRecorderFailure($eventName, $exception);
        $this->assertRecorderFailure(
            fn () => app(CreateContact::class)->execute($company, ['first_name' => 'Rolled Back']),
            $exception,
        );
        Event::forget($eventName);
        $this->assertDatabaseMissing('company_contacts', [
            'company_id' => $company->id,
            'first_name' => 'Rolled Back',
        ]);

        $updateCompany = $this->company('Contact Activity Update Rollback');
        $updateContact = $updateCompany->contacts()->create(['first_name' => 'Original']);
        $this->listenForRecorderFailure($eventName, $exception);
        $this->assertRecorderFailure(
            fn () => app(UpdateContact::class)->execute($updateContact, ['first_name' => 'Rolled Back']),
            $exception,
        );
        Event::forget($eventName);
        $this->assertSame('Original', $updateContact->fresh()->first_name);

        $deleteCompany = $this->company('Contact Activity Delete Rollback');
        $deleteContact = $deleteCompany->contacts()->create(['first_name' => 'Protected']);
        $this->listenForRecorderFailure($eventName, $exception);
        $this->assertRecorderFailure(
            fn () => app(DeleteContact::class)->execute($deleteContact),
            $exception,
        );
        Event::forget($eventName);
        $this->assertDatabaseHas('company_contacts', ['id' => $deleteContact->id]);
    }

    private function listenForRecorderFailure(string $eventName, RuntimeException $exception): void
    {
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });
    }

    private function assertRecorderFailure(callable $callback, RuntimeException $expected): void
    {
        try {
            $callback();
            $this->fail('The recorder failure should be propagated.');
        } catch (RuntimeException $caught) {
            $this->assertSame($expected, $caught);
        }
    }

    private function activityCount(mixed $company): int
    {
        return CompanyActivityEvent::query()
            ->where('company_id', $company->id)
            ->count();
    }
}
