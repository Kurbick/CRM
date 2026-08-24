<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyActivityEvent;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Services\CompanyActivityPresenter;
use App\Services\CompanyActivityQuery;
use App\Services\CompanyActivityRecorder;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivityVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class CompanyActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_recorder_persists_company_actor_and_snapshot_metadata_without_backfill(): void
    {
        $company = $this->company();
        $actor = $this->user([PermissionName::CompaniesView->value]);
        $occurredAt = CarbonImmutable::parse('2026-08-24 06:42:00', 'UTC');

        $event = app(CompanyActivityRecorder::class)->record(
            $company,
            CompanyActivityEventType::ContactCreated,
            CompanyActivityCategory::Contacts,
            CompanyActivityVisibilityScope::Contacts,
            subject: $company->contacts()->create(['first_name' => 'Snapshot Contact']),
            metadata: ['contact_name' => 'Snapshot Contact', 'irrelevant' => 'not needed by presenter'],
            actor: $actor,
            occurredAt: $occurredAt,
        );

        $this->assertSame($company->id, $event->company_id);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame(['contact_name' => 'Snapshot Contact', 'irrelevant' => 'not needed by presenter'], $event->metadata);
        $this->assertSame($occurredAt->getTimestamp(), $event->occurred_at->getTimestamp());
    }

    public function test_existing_business_records_do_not_create_historical_activity(): void
    {
        $company = $this->company();
        $contract = $company->contracts()->create([
            'contract_number' => 'OLD-CONTRACT',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'OLD-INVOICE',
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);

        $this->assertDatabaseCount('company_activity_events', 0);
    }

    public function test_events_are_immutable_by_application_contract(): void
    {
        $event = $this->event($this->company());

        $this->expectException(LogicException::class);
        $event->forceFill(['metadata' => ['changed' => true]])->save();
    }

    public function test_query_orders_by_occurred_at_then_id_and_filters_category(): void
    {
        $company = $this->company();
        $first = $this->event($company, CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, '2026-08-24 10:00:00');
        $second = $this->event($company, CompanyActivityEventType::ContactUpdated, CompanyActivityCategory::Contacts, '2026-08-24 10:00:00');
        $invoice = $this->event($company, CompanyActivityEventType::InvoiceCreated, CompanyActivityCategory::Invoices, '2026-08-24 11:00:00');
        $user = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);

        $page = app(CompanyActivityQuery::class)->paginate($user, $company);

        $this->assertSame([$invoice->id, $second->id, $first->id], collect($page->items())->pluck('id')->all());
        $filtered = app(CompanyActivityQuery::class)->paginate($user, $company, CompanyActivityCategory::Contacts);
        $this->assertSame([$second->id, $first->id], collect($filtered->items())->pluck('id')->all());
    }

    public function test_financial_visibility_is_enforced_before_activity_pagination(): void
    {
        $company = $this->company();
        $this->event($company, CompanyActivityEventType::InvoiceCreated, CompanyActivityCategory::Invoices, '2026-08-24 12:00:00', ['amount_minor' => 12345]);
        $this->event($company, CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, '2026-08-24 11:00:00');

        $withoutFinancials = $this->user([PermissionName::CompaniesView->value]);
        $hiddenPage = app(CompanyActivityQuery::class)->paginate($withoutFinancials, $company);
        $this->assertSame(1, $hiddenPage->count());
        $this->assertSame(CompanyActivityCategory::Contacts->value, $hiddenPage->first()->category);

        $withFinancials = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);
        $visiblePage = app(CompanyActivityQuery::class)->paginate($withFinancials, $company);
        $this->assertSame(2, $visiblePage->count());
    }

    public function test_contract_and_document_visibility_is_separate_from_company_visibility(): void
    {
        $company = $this->company();
        $contractEvent = $this->event($company, CompanyActivityEventType::ContractCreated, CompanyActivityCategory::Contracts, '2026-08-24 12:00:00');
        $contactEvent = $this->event($company, CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, '2026-08-24 11:00:00');

        $companyViewer = $this->user([PermissionName::CompaniesView->value]);
        $companyPage = app(CompanyActivityQuery::class)->paginate($companyViewer, $company);
        $this->assertSame([$contactEvent->id], collect($companyPage->items())->pluck('id')->all());

        $contractViewer = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);
        $contractPage = app(CompanyActivityQuery::class)->paginate($contractViewer, $company);
        $this->assertSame([$contractEvent->id, $contactEvent->id], collect($contractPage->items())->pluck('id')->all());
    }

    public function test_company_show_keeps_contacts_as_default_but_activity_is_first_and_empty_state_is_exact(): void
    {
        $company = $this->company('Empty Activity Company');
        $user = $this->user([PermissionName::CompaniesView->value]);
        $this->actingAs($user, 'web');

        $response = $this->get(route('companies.show', $company))->assertOk();

        $this->assertSame('contacts', $response->viewData('activeTab'));
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'Контакты ('), strpos($html, 'Активность'));
        $response->assertSee('Событий пока нет.')
            ->assertSee('Новые действия по компании будут появляться здесь.')
            ->assertSee('Все события')
            ->assertSee('name="activity_category"', false)
            ->assertSee('value="activity"', false);

        $this->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertOk()
            ->assertViewHas('activeTab', 'activity');
    }

    public function test_activity_tab_control_navigates_to_server_loaded_activity_url(): void
    {
        $company = $this->company('Navigable Activity Company');
        $this->event(
            $company,
            CompanyActivityEventType::ContractDeleted,
            CompanyActivityCategory::Contracts,
            metadata: [
                'contract_number' => 'CTR-NAV-001',
                'start_date' => '2026-08-01',
                'end_date' => '2026-09-01',
            ],
        );
        $user = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);
        $this->actingAs($user, 'web');

        $activityUrl = route('companies.show', [
            'company' => $company,
            'origin' => 'company',
            'tab' => 'activity',
        ]);

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertViewHas('activeTab', 'contacts')
            ->assertSee('href="?origin=company&amp;tab=activity"', false)
            ->assertDontSee('Удалён договор CTR-NAV-001');

        $this->get($activityUrl)
            ->assertOk()
            ->assertViewHas('activeTab', 'activity')
            ->assertSee('Удалён договор CTR-NAV-001');
    }

    public function test_company_show_queries_activity_only_for_the_activity_tab(): void
    {
        $company = $this->company('Conditional Activity Company');
        $user = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
        ]);
        $this->actingAs($user, 'web');

        foreach ([null, 'contacts', 'contracts', 'invoices', 'payments'] as $tab) {
            $queries = $this->captureActivityTableQueries(fn () => $this->get(route('companies.show', array_filter([
                'company' => $company,
                'tab' => $tab,
            ], fn ($value): bool => $value !== null)))->assertOk());

            $this->assertCount(0, $queries, $tab === null ? 'default contacts tab' : $tab.' tab');
        }

        $queries = $this->captureActivityTableQueries(fn () => $this->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))->assertOk());

        $this->assertCount(1, $queries, 'activity tab');
    }

    public function test_activity_renders_actor_and_locked_semantic_icons(): void
    {
        $company = $this->company('Rendered Activity Company');
        $actor = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
        ]);
        $this->event($company, CompanyActivityEventType::PaymentConfirmed, CompanyActivityCategory::Payments, '2026-08-24 12:00:00', ['amount_minor' => 60000], $actor);
        $this->event($company, CompanyActivityEventType::PaymentPendingCreated, CompanyActivityCategory::Payments, '2026-08-24 11:00:00');
        $this->event($company, CompanyActivityEventType::PaymentCancelled, CompanyActivityCategory::Payments, '2026-08-24 10:00:00', ['amount_minor' => 10000]);
        $this->event($company, CompanyActivityEventType::InvoiceCreated, CompanyActivityCategory::Invoices, '2026-08-24 09:00:00');
        $this->event($company, CompanyActivityEventType::DocumentUploaded, CompanyActivityCategory::Documents, '2026-08-24 08:00:00');
        $this->event($company, CompanyActivityEventType::ContractSubjectCreated, CompanyActivityCategory::Contracts, '2026-08-24 07:00:00');

        $this->actingAs($actor, 'web');
        $response = $this->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))->assertOk();

        $response->assertSee('Платёж 600,00 ₼ подтверждён')
            ->assertSee($actor->name)
            ->assertSee('M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', false)
            ->assertSee('M12 7.5V12l3 1.5', false)
            ->assertSee('m9.5 9.5 5 5', false)
            ->assertSee('M6.75 3.75h7.5l3 3v13.5H6.75V3.75Z', false)
            ->assertSee('m18.375 12.739-7.693 7.693', false)
            ->assertSee('M12 8.5v7', false)
            ->assertSee('grid-cols-[28px_155px_minmax(220px,1.2fr)_minmax(170px,1fr)_100px]', false)
            ->assertDontSee('Активность (');
    }

    public function test_activity_get_filter_keeps_activity_tab_and_filters_rows(): void
    {
        $company = $this->company('Filtered Activity Company');
        $this->event($company, CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, '2026-08-24 12:00:00');
        $this->event($company, CompanyActivityEventType::InvoiceCreated, CompanyActivityCategory::Invoices, '2026-08-24 11:00:00');
        $user = $this->user([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);
        $this->actingAs($user, 'web');

        $response = $this->get(route('companies.show', [
            'company' => $company,
            'tab' => 'activity',
            'activity_category' => 'invoices',
        ]))->assertOk();

        $response->assertViewHas('activeTab', 'activity')
            ->assertViewHas('activityCategory', CompanyActivityCategory::Invoices)
            ->assertSee('Инвойс создан')
            ->assertDontSee('Контакт создан')
            ->assertSee('value="activity"', false)
            ->assertSee('value="invoices" selected', false);
    }

    public function test_presenter_uses_local_today_yesterday_and_older_date_labels(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 20:00:00', 'Asia/Baku'));
        $company = $this->company('Date Activity Company');
        $presenter = app(CompanyActivityPresenter::class);
        $user = $this->user([PermissionName::CompaniesView->value]);

        try {
            $today = $this->event($company, occurredAt: '2026-08-24 15:42:00');
            $yesterday = $this->event($company, occurredAt: '2026-08-23 15:27:00');
            $older = $this->event($company, occurredAt: '2026-08-20 10:11:00');

            $this->assertSame('Сегодня, 19:42', $presenter->present($today, $user)['time_label']);
            $this->assertSame('Вчера, 19:27', $presenter->present($yesterday, $user)['time_label']);
            $this->assertSame('20/08/2026, 14:11', $presenter->present($older, $user)['time_label']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_subject_keeps_snapshot_without_broken_subject_link(): void
    {
        $company = $this->company();
        $contact = $company->contacts()->create(['first_name' => 'Deleted Snapshot']);
        $event = $this->event($company, CompanyActivityEventType::ContactDeleted, CompanyActivityCategory::Contacts, '2026-08-24 12:00:00', ['contact_name' => 'Deleted Snapshot'], null, $contact);
        $contact->delete();
        $user = $this->user([PermissionName::CompaniesView->value]);

        $page = app(CompanyActivityQuery::class)->paginate($user, $company);
        $availability = app(CompanyActivityQuery::class)->availableSubjectIds($user, $company, $page);
        $presentation = app(CompanyActivityPresenter::class)->present($page->first(), $user, $availability);

        $this->assertSame($event->id, $presentation['id']);
        $this->assertSame('Deleted Snapshot', $presentation['context']);
        $this->assertNull($presentation['subject_url']);
    }

    public function test_activity_page_query_count_is_constant_for_one_and_twenty_five_events(): void
    {
        $company = $this->company();
        $user = $this->user([PermissionName::CompaniesView->value]);
        $query = app(CompanyActivityQuery::class);

        $one = $this->captureActivityQueries(fn () => $query->paginate($user, $company));
        foreach (range(1, 24) as $index) {
            $this->event($company, CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, '2026-08-24 12:00:00');
        }
        $twentyFive = $this->captureActivityQueries(fn () => $query->paginate($user, $company));

        $this->assertSame(1, count($one));
        $this->assertSame(1, count($twentyFive));
        $this->assertSame(count($one), count($twentyFive));
    }

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function company(string $name = 'Activity Company'): Company
    {
        return Company::query()->create(['name' => $name, 'status' => 'active']);
    }

    /** @param array<string, mixed> $metadata */
    private function event(
        Company $company,
        CompanyActivityEventType $type = CompanyActivityEventType::CompanyUpdated,
        CompanyActivityCategory $category = CompanyActivityCategory::Company,
        string $occurredAt = '2026-08-24 12:00:00',
        array $metadata = [],
        ?User $actor = null,
        ?object $subject = null,
    ): CompanyActivityEvent {
        return app(CompanyActivityRecorder::class)->record(
            $company,
            $type,
            $category,
            $this->visibilityFor($category),
            subject: $subject,
            metadata: $metadata,
            actor: $actor,
            occurredAt: CarbonImmutable::parse($occurredAt, 'UTC'),
        );
    }

    private function visibilityFor(CompanyActivityCategory $category): CompanyActivityVisibilityScope
    {
        return match ($category) {
            CompanyActivityCategory::Contacts => CompanyActivityVisibilityScope::Contacts,
            CompanyActivityCategory::Contracts => CompanyActivityVisibilityScope::Contracts,
            CompanyActivityCategory::Invoices, CompanyActivityCategory::Payments => CompanyActivityVisibilityScope::Financials,
            CompanyActivityCategory::Documents => CompanyActivityVisibilityScope::Documents,
            default => CompanyActivityVisibilityScope::Company,
        };
    }

    /** @return list<string> */
    private function captureActivityQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'company_activity_events')) {
                $queries[] = $query->sql;
            }
        });
        $callback();

        return $queries;
    }

    /** @return list<string> */
    private function captureActivityTableQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'company_activity_events')) {
                $queries[] = $query->sql;
            }
        });
        $callback();

        return $queries;
    }
}
