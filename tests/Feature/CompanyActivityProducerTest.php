<?php

namespace Tests\Feature;

use App\Actions\ContractDocuments\DeleteContractDocument;
use App\Actions\ContractDocuments\StoreContractDocument;
use App\Actions\Contracts\CreateContract;
use App\Actions\Contracts\DeleteContract;
use App\Actions\Contracts\UpdateContract;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\DeleteOrder;
use App\Actions\Orders\UpdateOrder;
use App\Actions\Subscriptions\CreateSubscription;
use App\Actions\Subscriptions\DeleteSubscription;
use App\Actions\Subscriptions\UpdateSubscription;
use App\Exceptions\ContractDeletionException;
use App\Exceptions\SubscriptionDeletionException;
use App\Models\Company;
use App\Models\CompanyActivityEvent;
use App\Models\User;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityEventType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Authorization\AuthorizationTestCase;

class CompanyActivityProducerTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_contract_create_and_status_transition_record_once_and_noisy_update_records_zero(): void
    {
        $company = $this->company('Activity contract company');
        $actor = User::factory()->create(['name' => 'Contract operator']);

        $contract = app(CreateContract::class)->handle($company, [
            'contract_number' => 'CTR-ACT-001',
            'start_date' => '2026-08-01',
            'end_date' => '2027-11-01',
            'status' => 'active',
            'comment' => 'Initial contract',
        ], $actor);

        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'contract.created',
            'actor_user_id' => $actor->id,
            'visibility_scope' => 'contracts',
        ]);

        app(UpdateContract::class)->handle($contract, ['status' => 'terminated'], $actor);
        $this->assertSame(2, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'contract.status_changed',
        ]);

        app(UpdateContract::class)->handle($contract, ['comment' => 'Harmless edit'], $actor);
        $this->assertSame(2, $this->activityCount($company));
    }

    public function test_subscription_lifecycle_records_one_event_per_successful_operation(): void
    {
        $company = $this->company('Activity subscription company');
        $contract = $this->contract($company);
        $actor = User::factory()->create(['name' => 'Subscription operator']);

        $subscription = app(CreateSubscription::class)->handle($contract, [
            'service_name' => 'Support',
            'start_date' => '2026-08-01',
            'billing_period' => 'monthly',
            'amount' => '600.00',
            'payment_terms' => 14,
            'status' => 'active',
            'comment' => 'Support plan',
        ], $actor);
        $this->assertSame(1, $this->activityCount($company));

        app(UpdateSubscription::class)->handle($subscription, [
            'title' => 'Support Plus',
            'start_date' => '2026-08-01',
            'billing_period' => 'monthly',
            'amount' => '700.00',
            'payment_terms' => 14,
            'status' => 'active',
            'comment' => 'Updated support plan',
        ], $actor);
        $this->assertSame(2, $this->activityCount($company));

        app(DeleteSubscription::class)->handle($subscription, $actor);
        $this->assertSame(3, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'contract_subject.deleted',
        ]);
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'metadata->subject_name' => 'Support Plus',
        ]);
    }

    public function test_one_time_lifecycle_records_one_event_per_successful_operation(): void
    {
        $company = $this->company('Activity one-time company');
        $contract = $this->contract($company);
        $actor = User::factory()->create(['name' => 'One-time operator']);

        $order = app(CreateOrder::class)->handle($contract, [
            'service_name' => 'Разработка сайта',
            'order_date' => '2026-08-01',
            'price' => '1200.00',
            'payment_terms' => 14,
            'status' => 'in_progress',
            'comment' => 'Website work',
        ], $actor);
        $this->assertSame(1, $this->activityCount($company));

        app(UpdateOrder::class)->handle($order, [
            'title' => 'Разработка сайта v2',
            'order_date' => '2026-08-01',
            'price' => '1300.00',
            'payment_terms' => 14,
            'status' => 'in_progress',
            'comment' => 'Updated website work',
        ], $actor);
        $this->assertSame(2, $this->activityCount($company));

        app(DeleteOrder::class)->handle($order, $actor);
        $this->assertSame(3, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'metadata->subject_type' => 'one_time',
            'metadata->subject_name' => 'Разработка сайта v2',
        ]);
    }

    public function test_document_upload_and_delete_record_snapshot_events_once(): void
    {
        $company = $this->company('Activity document company');
        $contract = $this->contract($company);
        $actor = User::factory()->create(['name' => 'Document operator']);

        $document = app(StoreContractDocument::class)->handle(
            $contract,
            UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf'),
            'signed',
            null,
            $actor,
        );
        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'document.uploaded',
            'visibility_scope' => 'documents',
            'metadata->document_name' => 'contract.pdf',
        ]);

        app(DeleteContractDocument::class)->handle($document, $actor);
        $this->assertSame(2, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => 'document.deleted',
            'metadata->document_name' => 'contract.pdf',
        ]);
    }

    public function test_activity_insert_failure_rolls_back_contract_creation(): void
    {
        $company = $this->company('Activity rollback company');
        $exception = new \RuntimeException('activity insert failed');
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            app(CreateContract::class)->handle($company, [
                'contract_number' => 'CTR-ACT-ROLLBACK',
                'start_date' => '2026-08-01',
                'status' => 'active',
            ]);
            $this->fail('Activity failure did not roll back the contract.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseMissing('contracts', ['contract_number' => 'CTR-ACT-ROLLBACK']);
        $this->assertSame(0, $this->activityCount($company));
    }

    public function test_locked_subject_delete_is_rejected_without_activity(): void
    {
        $company = $this->company('Activity rejected delete company');
        $contract = $this->contract($company);
        $subscription = $this->subjectSubscription($contract);
        $this->subjectFinancialChain($subscription);

        try {
            app(DeleteSubscription::class)->handle($subscription);
            $this->fail('A locked subscription was deleted.');
        } catch (SubscriptionDeletionException) {
            $this->assertSame(0, $this->activityCount($company));
        }
    }

    public function test_authorization_denial_precedes_contract_subject_and_document_producers(): void
    {
        $company = $this->company('Activity authorization company');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->post(route('contracts.store'), [
            'company_id' => $company->id,
            'contract_number' => 'CTR-ACT-FORBIDDEN',
            'start_date' => '2026-08-01',
            'status' => 'active',
        ])->assertForbidden();

        $this->post(route('contracts.subscriptions.store', $contract), [
            'service_name' => 'Forbidden Support',
            'start_date' => '2026-08-01',
            'billing_period' => 'monthly',
            'amount' => '100.00',
            'payment_terms' => 14,
            'status' => 'active',
        ])->assertForbidden();

        $this->post(route('contracts.documents.store', $contract), [])->assertForbidden();

        $this->assertSame(0, $this->activityCount($company));
        $this->assertDatabaseMissing('contracts', ['contract_number' => 'CTR-ACT-FORBIDDEN']);
    }

    public function test_web_and_api_contract_deletion_each_record_exactly_one_event(): void
    {
        $webCompany = $this->company('Activity web delete company');
        $webContract = $this->contract($webCompany);
        $webContract->update([
            'contract_number' => 'CTR-ACT-WEB-DELETE',
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
        ]);
        $this->actingAsPermissions([
            PermissionName::ContractsDelete->value,
            PermissionName::CompaniesView->value,
        ]);

        $this->delete(route('contracts.destroy', $webContract))->assertRedirect();
        $this->assertSame(1, $this->activityCount($webCompany));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $webCompany->id,
            'event_type' => CompanyActivityEventType::ContractDeleted->value,
            'metadata->contract_number' => 'CTR-ACT-WEB-DELETE',
        ]);

        $apiCompany = $this->company('Activity API delete company');
        $apiContract = $this->contract($apiCompany);
        $apiContract->update([
            'contract_number' => 'CTR-ACT-API-DELETE',
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
        ]);
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->deleteJson(route('api.contracts.destroy', $apiContract))->assertOk();
        $this->assertSame(1, $this->activityCount($apiCompany));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $apiCompany->id,
            'event_type' => CompanyActivityEventType::ContractDeleted->value,
            'metadata->contract_number' => 'CTR-ACT-API-DELETE',
        ]);
    }

    public function test_contract_delete_snapshot_survives_and_does_not_create_child_events(): void
    {
        $company = $this->company('Activity deleted snapshot company');
        $contract = $this->contract($company);
        $contract->update([
            'contract_number' => 'CTR-ACT-SNAPSHOT',
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-01',
            'status' => 'active',
        ]);
        $actor = User::factory()->create(['name' => 'Delete operator']);

        app(DeleteContract::class)->handle($contract, $actor);

        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
        $event = CompanyActivityEvent::query()->where('company_id', $company->id)->sole();
        $this->assertSame(CompanyActivityEventType::ContractDeleted->value, $event->event_type);
        $this->assertSame('CTR-ACT-SNAPSHOT', $event->metadata['contract_number']);
        $this->assertSame('2026-08-01', $event->metadata['start_date']);
        $this->assertSame('2026-09-01', $event->metadata['end_date']);
        $this->assertSame('active', $event->metadata['status']);
        $this->assertSame($actor->id, $event->actor_user_id);
    }

    public function test_forbidden_or_blocked_contract_delete_creates_no_activity(): void
    {
        $company = $this->company('Activity forbidden delete company');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->delete(route('contracts.destroy', $contract))->assertForbidden();
        $this->assertSame(0, $this->activityCount($company));

        $blockedCompany = $this->company('Activity blocked delete company');
        $blockedContract = $this->contract($blockedCompany);
        $this->subjectOrder($blockedContract);

        try {
            app(DeleteContract::class)->handle($blockedContract);
            $this->fail('A contract with dependencies was deleted.');
        } catch (ContractDeletionException) {
            $this->assertSame(0, $this->activityCount($blockedCompany));
        }
    }

    public function test_contract_delete_rolls_back_when_activity_insert_fails(): void
    {
        $company = $this->company('Activity delete rollback company');
        $contract = $this->contract($company);
        $contract->update(['contract_number' => 'CTR-ACT-DELETE-ROLLBACK']);
        $exception = new \RuntimeException('activity insert failed');
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            app(DeleteContract::class)->handle($contract);
            $this->fail('Activity failure did not roll back the contract delete.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertSame(0, $this->activityCount($company));
    }

    private function activityCount(Company $company): int
    {
        return CompanyActivityEvent::query()->where('company_id', $company->id)->count();
    }
}
