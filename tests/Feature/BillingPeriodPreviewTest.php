<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyActivityEvent;
use App\Models\Contract;
use App\Models\CreditBalance;
use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Services\BillingPeriodPreview;
use App\Services\SubscriptionBillingSchedule;
use App\Support\Access\PermissionName;
use Carbon\Carbon;
use App\Support\CompanyActivityEventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class BillingPeriodPreviewTest extends AuthorizationTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_card_and_current_period_preview_are_read_only_and_canonical(): void
    {
        $subscription = $this->subscription();
        $before = [
            'invoices' => Invoice::query()->count(),
            'next_billing_date' => $subscription->fresh()->next_billing_date->toDateString(),
        ];

        $user = $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
        ]);

        $dashboard = $this->get(route('dashboard'))->assertOk();
        $dashboard
            ->assertSee('Требует внимания')
            ->assertSee('К выставлению')
            ->assertSee('1')
            ->assertSee('238,00 ₼')
            ->assertSee(route('invoices.billing'), false);

        $preview = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('← Назад к дашборду')
            ->assertSee('data-testid="billing-back-dashboard"', false)
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('Выбрать все')
            ->assertSee('name="selected_occurrences[]"', false)
            ->assertSee('Создать черновики')
            ->assertSee('К созданию')
            ->assertSee('Тестовая компания')
            ->assertSee('CTR-2026-001')
            ->assertSee('Мобильное приложение')
            ->assertSee('01/09/2026 — 30/09/2026')
            ->assertSee('200,00 ₼')
            ->assertSee('38,00 ₼')
            ->assertSee('238,00 ₼');

        $this->assertSame(1, $preview->viewData('preview')['count']);
        $this->assertSame($before['invoices'], Invoice::query()->count());
        $this->assertSame($before['next_billing_date'], $subscription->fresh()->next_billing_date->toDateString());
        $this->assertTrue($user->can(PermissionName::InvoicesCreate->value));
    }

    public function test_arbitrary_month_and_contract_boundary_are_respected(): void
    {
        $subscription = $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $subscription->contract->update(['end_date' => '2026-10-31']);

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $response = $this->get(route('invoices.billing.preview', ['month' => 10, 'year' => 2026]))
            ->assertOk();

        $this->assertSame(0, $response->viewData('preview')['count']);
        $this->assertSame(1, $response->viewData('preview')['planned_count']);
        $this->assertSame('01/10/2026 — 31/10/2026', $response->viewData('preview')['rows'][0]['period']);

        $subscription->contract->update(['end_date' => '2026-10-30']);
        $this->get(route('invoices.billing.preview', ['month' => 10, 'year' => 2026]))
            ->assertOk()
            ->assertSee('За октябрь 2026 счетов к выставлению нет.');
    }

    public function test_month_query_changes_the_billing_preview_period(): void
    {
        $subscription = $this->subscription([
            'start_date' => '2026-09-01',
            'next_billing_date' => '2026-09-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $expected = [
            2 => ['title' => 'Предварительный просмотр за февраль 2026', 'empty' => 'За февраль 2026 счетов к выставлению нет.'],
            9 => ['title' => 'Предварительный просмотр за сентябрь 2026', 'period' => '01/09/2026 — 30/09/2026'],
            10 => ['title' => 'Предварительный просмотр за октябрь 2026', 'period' => '01/10/2026 — 31/10/2026'],
        ];

        foreach ($expected as $month => $assertions) {
            $response = $this->get(route('invoices.billing.preview', [
                'month' => $month,
                'year' => 2026,
            ]))->assertOk();

            $response
                ->assertSee($assertions['title'])
                ->assertSee('id="billing-period-form" method="GET" action="'.route('invoices.billing.preview').'"', false)
                ->assertSee('onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"', false)
                ->assertDontSee('Найти счета за период')
                ->assertSee('option value="'.$month.'" selected', false)
                ->assertSee('name="tab" value="pending"', false)
                ->assertSee('href="'.htmlspecialchars(route('invoices.billing.preview', ['month' => $month, 'year' => 2026, 'tab' => 'pending']), ENT_QUOTES, 'UTF-8').'"', false)
                ->assertSee('href="'.htmlspecialchars(route('invoices.billing.preview', ['month' => $month, 'year' => 2026, 'tab' => 'drafts']), ENT_QUOTES, 'UTF-8').'"', false)
                ->assertDontSee('selectTab');
            $this->assertSame(1, substr_count($response->getContent(), 'name="month"'));
            $this->assertSame(1, substr_count($response->getContent(), 'name="year"'));
            $this->assertSame($month, $response->viewData('period')->month);
            $this->assertSame(2026, $response->viewData('period')->year);

            if (isset($assertions['empty'])) {
                $response->assertSee($assertions['empty']);
                $this->assertSame(0, $response->viewData('preview')['count']);
            } else {
                $response->assertSee($assertions['period']);
                $this->assertSame($assertions['period'], $response->viewData('preview')['rows'][0]['period']);
                $this->assertSame($subscription->id, $response->viewData('preview')['rows'][0]['subscription_id']);
            }
        }
    }

    public function test_current_period_shows_all_occurrences_but_only_due_occurrences_are_eligible(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $seventh = $this->subscription([
            'start_date' => '2026-09-07',
            'next_billing_date' => '2026-09-07',
        ]);
        $eighth = $this->subscription([
            'start_date' => '2026-09-08',
            'next_billing_date' => '2026-09-08',
        ]);
        $thirteenth = $this->subscription([
            'start_date' => '2026-09-13',
            'next_billing_date' => '2026-09-13',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))->assertOk();
        $rows = collect($response->viewData('preview')['rows'])->keyBy('subscription_id');
        $this->assertCount(3, $rows);
        $this->assertSame(2, $response->viewData('preview')['count']);
        $this->assertSame(3, $response->viewData('preview')['planned_count']);
        $this->assertTrue($rows[$seventh->id]['eligible_for_draft_creation']);
        $this->assertTrue($rows[$eighth->id]['eligible_for_draft_creation']);
        $this->assertFalse($rows[$thirteenth->id]['eligible_for_draft_creation']);
        $this->assertSame('scheduled', $rows[$thirteenth->id]['queue_status']);
        $this->assertSame('2026-09-13', $rows[$thirteenth->id]['scheduled_billing_date']);
        $response
            ->assertSee('Всего:')
            ->assertSee('К выставлению:')
            ->assertSee('К выставлению с')
            ->assertSee('13.09.2026')
            ->assertSee('class="mt-0.5 block text-xs font-medium text-blue-700"', false)
            ->assertSee('disabled', false);

        $createResponse = $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [
                $rows[$seventh->id]['identity'],
                $rows[$eighth->id]['identity'],
            ],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(
            ['2026-09-07', '2026-09-08'],
            Invoice::query()->with('lines')->get()
                ->flatMap(fn (Invoice $invoice) => $invoice->lines->pluck('period_start'))
                ->map(fn ($date): string => $date->toDateString())
                ->sort()
                ->values()
                ->all(),
        );

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$rows[$thirteenth->id]['identity']],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame(2, Invoice::query()->count());

        Carbon::setTestNow('2026-09-13 10:00:00');
        $boundary = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))->assertOk();
        $boundaryRow = collect($boundary->viewData('preview')['rows'])->firstWhere('subscription_id', $thirteenth->id);
        $this->assertTrue($boundaryRow['eligible_for_draft_creation']);
        $this->assertSame('pending', $boundaryRow['queue_status']);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$boundaryRow['identity']],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame(3, Invoice::query()->count());
        $this->assertSame('2026-09-13', Invoice::query()->latest('id')->firstOrFail()->lines()->firstOrFail()->period_start->toDateString());
    }

    public function test_past_period_missing_occurrence_is_eligible_for_billing_run(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $subscription = $this->subscription([
            'start_date' => '2026-08-07',
            'next_billing_date' => '2026-08-07',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 8, 'year' => 2026]))->assertOk();
        $row = $response->viewData('preview')['rows'][0];
        $this->assertTrue($row['eligible_for_draft_creation']);
        $this->assertSame('pending', $row['queue_status']);

        $this->post(route('invoices.billing.drafts', ['month' => 8, 'year' => 2026]), [
            'selected_occurrences' => [$row['identity']],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame('2026-08-07', Invoice::query()->with('lines')->sole()->lines->sole()->period_start->toDateString());
    }

    public function test_future_period_is_visible_but_cannot_create_a_draft(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $subscription = $this->subscription([
            'start_date' => '2026-12-07',
            'next_billing_date' => '2026-12-07',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 12, 'year' => 2026]))->assertOk();
        $row = $response->viewData('preview')['rows'][0];
        $this->assertSame('scheduled', $row['queue_status']);
        $this->assertFalse($row['eligible_for_draft_creation']);
        $response
            ->assertSee('К выставлению с')
            ->assertSee('07.12.2026');

        $this->post(route('invoices.billing.drafts', ['month' => 12, 'year' => 2026]), [
            'selected_occurrences' => [$row['identity']],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_due_occurrence_reports_the_number_of_earlier_unprocessed_occurrences(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $oneMissed = $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $threeMissed = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))->assertOk();
        $rows = collect($response->viewData('preview')['rows'])->keyBy('subscription_id');

        $this->assertSame('missed', $rows[$oneMissed->id]['queue_status']);
        $this->assertSame(1, $rows[$oneMissed->id]['missed_count']);
        $this->assertFalse($rows[$oneMissed->id]['eligible_for_draft_creation']);
        $this->assertSame('missed', $rows[$threeMissed->id]['queue_status']);
        $this->assertSame(3, $rows[$threeMissed->id]['missed_count']);
        $this->assertFalse($rows[$threeMissed->id]['eligible_for_draft_creation']);
        $response
            ->assertSee('Пропущенный период')
            ->assertSee('Пропущено периодов:');

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$rows[$oneMissed->id]['identity'], $rows[$threeMissed->id]['identity']],
        ])->assertRedirect(route('invoices.billing.result'));
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_missed_occurrence_link_exposes_the_canonical_singular_period_and_target(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $subscription = $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk();
        $row = collect($response->viewData('preview')['rows'])->firstWhere('subscription_id', $subscription->id);

        $this->assertSame([
            [
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'period_display' => '01.08.2026 — 31.08.2026',
                'month' => 8,
                'year' => 2026,
            ],
        ], $row['missed_occurrences']);
        $response
            ->assertSee('data-testid="missed-occurrence-link"', false)
            ->assertSee('data-testid="missed-occurrence-popover"', false)
            ->assertSee('class="group relative mt-0.5 block"', false)
            ->assertSee('class="block whitespace-normal text-left text-xs font-medium text-rose-700', false)
            ->assertSeeText('01.08.2026 — 31.08.2026')
            ->assertSeeText('Перейти к августу 2026')
            ->assertSee('href="'.htmlspecialchars(route('invoices.billing.preview', [
                'tab' => 'pending',
                'month' => 8,
                'year' => 2026,
            ]), ENT_QUOTES, 'UTF-8').'"', false);
    }

    public function test_multiple_missed_occurrences_link_to_the_earliest_canonical_period(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $subscription = $this->subscription([
            'start_date' => '2026-06-01',
            'next_billing_date' => '2026-06-01',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk();
        $row = collect($response->viewData('preview')['rows'])->firstWhere('subscription_id', $subscription->id);

        $this->assertSame(3, $row['missed_count']);
        $this->assertSame([
            '2026-06-01',
            '2026-07-01',
            '2026-08-01',
        ], collect($row['missed_occurrences'])->pluck('period_start')->all());
        $response
            ->assertSee('Пропущено периодов: 3')
            ->assertSeeText('01.06.2026 — 30.06.2026')
            ->assertSeeText('01.07.2026 — 31.07.2026')
            ->assertSeeText('01.08.2026 — 31.08.2026')
            ->assertSeeText('Сначала к выставлению: июнь 2026')
            ->assertSee('href="'.htmlspecialchars(route('invoices.billing.preview', [
                'tab' => 'pending',
                'month' => 6,
                'year' => 2026,
            ]), ENT_QUOTES, 'UTF-8').'"', false);
    }

    public function test_missed_occurrence_navigation_handles_a_previous_year_without_writing_state(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $subscription = $this->subscription([
            'start_date' => '2025-12-01',
            'next_billing_date' => '2025-12-01',
        ]);
        $subscription->contract->update(['start_date' => '2025-12-01']);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $before = [
            'invoices' => Invoice::query()->count(),
            'next_billing_date' => $subscription->fresh()->next_billing_date->toDateString(),
        ];

        $response = $this->get(route('invoices.billing.preview', ['month' => 1, 'year' => 2026]))
            ->assertOk();
        $row = collect($response->viewData('preview')['rows'])->firstWhere('subscription_id', $subscription->id);

        $this->assertSame('2025-12-01', $row['missed_occurrences'][0]['period_start']);
        $this->assertSame('2025-12-31', $row['missed_occurrences'][0]['period_end']);
        $response->assertSee('href="'.htmlspecialchars(route('invoices.billing.preview', [
            'tab' => 'pending',
            'month' => 12,
            'year' => 2025,
        ]), ENT_QUOTES, 'UTF-8').'"', false);

        $target = $this->get(route('invoices.billing.preview', [
            'tab' => 'pending',
            'month' => 12,
            'year' => 2025,
        ]))->assertOk();
        $targetRow = collect($target->viewData('preview')['rows'])->firstWhere('subscription_id', $subscription->id);

        $this->assertSame('pending', $targetRow['queue_status']);
        $this->assertSame($before['invoices'], Invoice::query()->count());
        $this->assertSame($before['next_billing_date'], $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_existing_occurrence_is_excluded_by_source_identity_and_cancelled_invoice_is_not_a_reservation(): void
    {
        $subscription = $this->subscription();
        $periodStart = Carbon::parse('2026-09-01')->toImmutable();
        $periodEnd = Carbon::parse('2026-09-30')->toImmutable();
        $key = app(SubscriptionBillingSchedule::class)->occurrenceKey($subscription->id, $periodStart, $periodEnd);
        $invoice = Invoice::query()->create([
            'company_id' => $subscription->contract->company_id,
            'issuer_organization_id' => $this->organization()->id,
            'contract_id' => $subscription->contract_id,
            'invoice_number' => 'BILLING-EXISTING',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'total_amount' => '238.00',
            'status' => 'issued',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Different description must not matter',
            'amount' => '999.00',
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'billing_occurrence_key' => $key,
        ]);

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk();
        $this->assertSame(0, $response->viewData('preview')['count']);

        $invoice->update(['status' => 'cancelled']);
        $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('238,00 ₼');
    }

    public function test_inactive_subscription_and_vat_neutral_issuer_are_handled(): void
    {
        $this->subscription(['status' => 'suspended']);
        $active = $this->subscription(['title' => 'Neutral VAT service']);
        $organization = $this->organization();
        $organization->update(['is_vat_payer' => false, 'vat_rate' => null]);

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $response = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Neutral VAT service')
            ->assertSee('200,00 ₼')
            ->assertSee('0,00 ₼');

        $this->assertSame('0.00', $response->viewData('preview')['vat']);
        $this->assertSame('200.00', $response->viewData('preview')['total']);
        $this->assertSame(1, $response->viewData('preview')['count']);
        $this->assertSame('2026-09-01', $active->fresh()->next_billing_date->toDateString());
    }

    public function test_dashboard_shows_a_non_warning_zero_state_and_custom_schedule_has_no_duplicate_occurrences(): void
    {
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
        ]);
        $dashboard = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('К выставлению')
            ->assertSee('0')
            ->assertSee('За сентябрь 2026 счетов к выставлению нет');
        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="dashboard-billing-card"[^>]*border-red/',
            $dashboard->getContent(),
        );

        $this->subscription([
            'title' => 'Десятидневная услуга',
            'billing_period' => 'custom',
            'custom_interval_value' => 10,
            'custom_interval_unit' => 'day',
        ]);
        $preview = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->viewData('preview');

        $this->assertSame(1, $preview['count']);
        $this->assertSame(3, $preview['planned_count']);
        $this->assertCount(3, collect($preview['rows'])->pluck('period')->unique());
    }

    public function test_viewer_cannot_see_or_open_billing_workflow(): void
    {
        $this->subscription();
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesView->value,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('К выставлению')
            ->assertDontSee(route('invoices.billing'), false);
        $this->get(route('invoices.billing'))->assertForbidden();
        $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))->assertForbidden();
        $this->get(route('invoices.billing.result'))->assertForbidden();
    }

    public function test_preview_uses_one_domain_query_for_the_collection_and_supports_az(): void
    {
        $this->subscription(['title' => 'Xidmət']);
        $user = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $capture = app(DomainQueryRecorder::class)->capture(
            fn () => $this->withSession(['locale' => 'az'])
                ->actingAs($user)
                ->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
        );

        $capture['result']
            ->assertOk()
            ->assertSee('Dövr üzrə hesabların rəsmiləşdirilməsi')
            ->assertSee('← Əsas səhifəyə qayıt')
            ->assertSee('Xidmət / Təsvir');
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
    }

    public function test_selected_occurrence_creates_a_canonical_draft_without_financial_side_effects(): void
    {
        $subscription = $this->subscription();
        $company = $subscription->contract->company;
        $creditBalance = CreditBalance::query()->create([
            'company_id' => $company->id,
            'amount' => '500.00',
        ]);
        $identity = $this->previewIdentity($subscription);
        $paymentsBefore = Payment::query()->count();
        $creditEntriesBefore = CreditBalanceEntry::query()->count();

        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);
        $response = $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$identity],
            'amount' => '0.01',
            'vat_amount' => '0.01',
            'company_id' => 999999,
            'status' => 'issued',
        ]);
        $response->assertRedirect(route('invoices.billing.result'));
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Черновики')
            ->assertSee('Создано:')
            ->assertSee('Черновик')
            ->assertSee('Открыть');

        $invoice = Invoice::query()->sole();
        $line = $invoice->lines()->sole();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame($subscription->contract_id, $invoice->contract_id);
        $this->assertSame($subscription->id, $line->subscription_id);
        $this->assertSame('Мобильное приложение', $line->description);
        $this->assertSame('2026-09-01', $line->period_start->toDateString());
        $this->assertSame('2026-09-30', $line->period_end->toDateString());
        $this->assertSame('200.00', $line->amount);
        $this->assertSame('200.00', $invoice->subtotal_amount);
        $this->assertTrue($invoice->vat_enabled);
        $this->assertSame('19.00', $invoice->vat_rate);
        $this->assertSame('38.00', $invoice->vat_amount);
        $this->assertSame('238.00', $invoice->total_amount);
        $this->assertSame('2026-09-15', substr((string) $invoice->issue_date, 0, 10));
        $this->assertSame('2026-09-29', substr((string) $invoice->due_date, 0, 10));
        $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
        $this->assertSame($paymentsBefore, Payment::query()->count());
        $this->assertSame($creditEntriesBefore, CreditBalanceEntry::query()->count());
        $this->assertSame('500.00', $creditBalance->fresh()->amount);
    }

    public function test_selected_occurrence_creates_a_vat_neutral_draft_when_issuer_is_not_a_vat_payer(): void
    {
        $subscription = $this->subscription();
        $this->organization()->update(['is_vat_payer' => false, 'vat_rate' => null]);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ])->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertFalse($invoice->vat_enabled);
        $this->assertNull($invoice->vat_rate);
        $this->assertSame('0.00', $invoice->vat_amount);
        $this->assertSame('200.00', $invoice->subtotal_amount);
        $this->assertSame('200.00', $invoice->total_amount);
    }

    public function test_unselected_occurrence_is_not_created_and_multiple_selected_occurrences_create_multiple_drafts(): void
    {
        $first = $this->subscription(['title' => 'Первая услуга']);
        $second = $this->subscription(['title' => 'Вторая услуга']);
        $identities = [$this->previewIdentity($first), $this->previewIdentity($second)];

        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesIssue->value,
        ]);
        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$identities[0]],
        ])->assertRedirect();
        $this->assertSame(1, Invoice::query()->count());

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$identities[1]],
        ])->assertRedirect();
        $this->assertSame(2, Invoice::query()->count());
        $this->assertDatabaseHas('invoice_lines', [
            'subscription_id' => $first->id,
            'billing_occurrence_key' => $this->occurrenceKey($first),
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'subscription_id' => $second->id,
            'billing_occurrence_key' => $this->occurrenceKey($second),
        ]);

        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(2, $dashboard->viewData('billingSummary')['preview']['count']);
        $this->assertSame('476.00', $dashboard->viewData('billingSummary')['preview']['total']);

        $this->post(route('invoices.issue', Invoice::query()->orderBy('id')->firstOrFail()))
            ->assertRedirect();
        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $dashboard->viewData('billingSummary')['preview']['count']);
        $this->assertSame('238.00', $dashboard->viewData('billingSummary')['preview']['total']);
    }

    public function test_zero_selection_is_rejected_without_creating_an_invoice(): void
    {
        $this->subscription();
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [],
        ])->assertSessionHasErrors('selected_occurrences');

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_stale_or_repeated_selection_is_skipped_and_cannot_create_a_duplicate(): void
    {
        $subscription = $this->subscription();
        $identity = $this->previewIdentity($subscription);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$identity],
        ])->assertRedirect();
        $this->assertSame(1, Invoice::query()->count());

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$identity],
        ])->assertRedirect();
        $this->assertSame(1, Invoice::query()->count());

        $this->get(route('invoices.billing.result'))
            ->assertOk()
            ->assertSee('Черновики')
            ->assertSee('Создано:')
            ->assertSee('Пропущено:')
            ->assertSee('Счёт для этого периода уже существует');
    }

    public function test_billing_result_survives_invoice_navigation_and_uses_canonical_invoice_data(): void
    {
        $first = $this->subscription(['title' => 'Первая услуга']);
        $second = $this->subscription(['title' => 'Вторая услуга']);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);

        $response = $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [
                $this->previewIdentity($first),
                $this->previewIdentity($second),
            ],
        ]);
        $response->assertRedirect(route('invoices.billing.result'));

        $invoices = Invoice::query()->orderBy('id')->get();
        $this->assertCount(2, $invoices);
        $result = $this->get(route('invoices.billing.result'))
            ->assertOk()
            ->assertSee('Черновики')
            ->assertSee('Создано:')
            ->assertSee($invoices[0]->invoice_number)
            ->assertSee($invoices[1]->invoice_number)
            ->assertSee('238,00 ₼');

        $result->assertSee(route('invoices.show', [
            'invoice' => $invoices[0]->id,
            'billing_result' => 1,
        ]), false);

        $invoices[0]->update(['total_amount' => '999.00']);
        $this->get(route('invoices.show', [
            'invoice' => $invoices[0]->id,
            'billing_result' => 1,
        ]))
            ->assertOk()
            ->assertSee('Назад к черновикам')
            ->assertDontSee('← Назад к черновикам')
            ->assertSee(route('invoices.billing.result'), false);

        $this->get(route('invoices.billing.result'))
            ->assertOk()
            ->assertSee($invoices[0]->invoice_number)
            ->assertSee('999,00 ₼')
            ->assertSee($invoices[1]->invoice_number);
        $preview = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Черновик')
            ->assertSee('data-row-url="'.htmlspecialchars(route('invoices.show', [
                'invoice' => $invoices[0]->id,
                'billing_preview' => 1,
                'month' => 9,
                'year' => 2026,
                'tab' => 'drafts',
            ]), ENT_QUOTES, 'UTF-8').'"', false);
        $this->assertSame(2, $preview->viewData('preview')['count']);
        $this->assertCount(
            2,
            collect($preview->viewData('preview')['rows'])
                ->where('queue_status', 'draft')
        );

        $this->get(route('invoices.show', [
            'invoice' => $invoices[0]->id,
            'billing_preview' => 1,
            'month' => 9,
            'year' => 2026,
        ]))
            ->assertOk()
            ->assertSee('Назад к выставлению счетов')
            ->assertSee(htmlspecialchars(
                route('invoices.billing.preview', ['month' => 9, 'year' => 2026]),
                ENT_QUOTES,
                'UTF-8'
            ), false);
    }

    public function test_deleting_a_billing_result_draft_returns_to_the_same_run_and_rebuilds_state(): void
    {
        $subscription = $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $user = $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesDelete->value,
        ]);

        $preview = $this->get(route('invoices.billing.preview', [
            'month' => 8,
            'year' => 2026,
            'tab' => 'pending',
        ]))->assertOk();
        $row = $preview->viewData('preview')['rows'][0];

        $this->post(route('invoices.billing.drafts', ['month' => 8, 'year' => 2026]), [
            'selected_occurrences' => [$row['identity']],
        ])->assertRedirect(route('invoices.billing.result'));

        $invoice = Invoice::query()->sole();
        $this->get(route('invoices.show', [
            'invoice' => $invoice,
            'billing_result' => 1,
        ]))
            ->assertOk()
            ->assertSee('name="billing_result" value="1"', false);

        $this->assertSame(8, session('billing_run_result.month'));
        $this->assertSame(2026, session('billing_run_result.year'));
        $this->assertSame('drafts', session('billing_run_result.tab'));

        $this->delete(route('invoices.destroy', [
            'invoice' => $invoice,
            'billing_result' => 1,
        ]))
            ->assertRedirect(route('invoices.billing.result'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->get(route('invoices.billing.result'))
            ->assertOk()
            ->assertDontSee($invoice->invoice_number)
            ->assertSee(htmlspecialchars(
                route('invoices.billing.preview', [
                    'month' => 8,
                    'year' => 2026,
                    'tab' => 'drafts',
                ]),
                ENT_QUOTES,
                'UTF-8'
            ), false);

        $canonical = $this->get(route('invoices.billing.preview', [
            'month' => 8,
            'year' => 2026,
            'tab' => 'drafts',
        ]))->assertOk();
        $restored = $canonical->viewData('preview')['rows'][0];
        $this->assertSame('pending', $restored['queue_status']);
        $this->assertTrue($restored['eligible_for_draft_creation']);
        $this->assertNull($restored['invoice_id']);
        $canonical->assertSee('К выставлению');
    }

    public function test_billing_result_is_bound_to_the_authenticated_user(): void
    {
        $subscription = $this->subscription();
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ])->assertRedirect(route('invoices.billing.result'));

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $this->get(route('invoices.billing.result'))->assertNotFound();
    }

    public function test_forged_inactive_or_out_of_period_occurrence_is_skipped(): void
    {
        $inactive = $this->subscription(['status' => 'suspended']);
        $active = $this->subscription();
        $forgedInactive = (string) $inactive->id.':'.str_repeat('a', 64);
        $forgedOutOfPeriod = (string) $active->id.':'.$this->occurrenceKeyFor(
            $active,
            '2026-10-01',
            '2026-10-31',
        );

        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $response = $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$forgedInactive, $forgedOutOfPeriod],
        ]);

        $response->assertRedirect();
        $this->assertSame(0, Invoice::query()->count());
        $this->get(route('invoices.billing.result'))
            ->assertOk()
            ->assertSee('Пропущено:');
    }

    public function test_create_draft_post_is_denied_without_invoice_create_permission(): void
    {
        $subscription = $this->subscription();
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ])->assertForbidden();

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_dashboard_and_preview_keep_an_existing_draft_in_the_billing_queue(): void
    {
        $subscription = $this->subscription();
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ])->assertRedirect();

        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $dashboard->viewData('billingSummary')['preview']['count']);
        $this->assertSame('238.00', $dashboard->viewData('billingSummary')['preview']['total']);

        $preview = $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Черновик')
            ->assertSee('data-row-url="'.htmlspecialchars(route('invoices.show', [
                'invoice' => Invoice::query()->sole()->id,
                'billing_preview' => 1,
                'month' => 9,
                'year' => 2026,
                'tab' => 'drafts',
            ]), ENT_QUOTES, 'UTF-8').'"', false);
        $row = $preview->viewData('preview')['rows'][0];
        $this->assertSame('draft', $row['queue_status']);
        $this->assertFalse($row['eligible_for_draft_creation']);
        $this->assertSame(1, $preview->viewData('preview')['count']);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ])->assertRedirect();
        $this->assertSame(1, Invoice::query()->count());

        $invoice = Invoice::query()->sole();
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesIssue->value,
        ]);
        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(0, $dashboard->viewData('billingSummary')['preview']['count']);
        $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('За сентябрь 2026 счетов к выставлению нет.');
    }

    public function test_selected_drafts_can_be_issued_from_the_billing_run_and_stale_ids_are_skipped(): void
    {
        $first = $this->subscription(['title' => 'Первый счёт']);
        $second = $this->subscription(['title' => 'Второй счёт']);
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesIssue->value,
        ]);

        $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [
                $this->previewIdentity($first),
                $this->previewIdentity($second),
            ],
        ])->assertRedirect();

        $invoices = Invoice::query()->orderBy('id')->get();
        $balances = $invoices->mapWithKeys(
            fn (Invoice $invoice): array => [$invoice->id => $invoice->company->creditBalance()->create(['amount' => '100.00'])]
        );
        $response = $this->post(route('invoices.billing.issue', ['month' => 9, 'year' => 2026]), [
            'selected_invoices' => [$invoices[0]->id],
            'status' => 'paid',
            'total_amount' => '0.01',
            'company_id' => 999999,
            'issue_date' => '1900-01-01',
        ]);
        $response->assertRedirect(route('invoices.billing.result'));
        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Счета выставлены')
            ->assertSee('Создано/выставлено:')
            ->assertSee('Пропущено:')
            ->assertSee($invoices[0]->invoice_number)
            ->assertSee('Тестовая компания')
            ->assertSee('Выставлен')
            ->assertSee('Открыть');

        $this->assertSame('issued', $invoices[0]->fresh()->status);
        $this->assertSame('100.00', $balances[$invoices[0]->id]->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoices[0]->id]);
        $this->assertSame('draft', $invoices[1]->fresh()->status);
        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $dashboard->viewData('billingSummary')['preview']['count']);

        $this->post(route('invoices.billing.issue', ['month' => 9, 'year' => 2026]), [
            'selected_invoices' => [$invoices[0]->id, $invoices[1]->id],
        ])->assertRedirect(route('invoices.billing.result'));

        $this->assertSame('issued', $invoices[1]->fresh()->status);
        $this->assertSame('100.00', $balances[$invoices[1]->id]->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoices[1]->id]);
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(0, $this->get(route('dashboard'))->viewData('billingSummary')['preview']['count']);
        $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('За сентябрь 2026 счетов к выставлению нет.');
    }

    public function test_billing_run_draft_edit_records_the_normal_invoice_update_activity(): void
    {
        $subscription = $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $actor = $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
        ]);

        $preview = $this->get(route('invoices.billing.preview', [
            'month' => 8,
            'year' => 2026,
        ]))->assertOk();
        $row = $preview->viewData('preview')['rows'][0];

        $this->post(route('invoices.billing.drafts', ['month' => 8, 'year' => 2026]), [
            'selected_occurrences' => [$row['identity']],
        ])->assertRedirect(route('invoices.billing.result'));

        $invoice = Invoice::query()->sole();
        $line = $invoice->lines()->sole();

        $this->put(route('invoices.update', $invoice), [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => Carbon::parse($invoice->issue_date)->toDateString(),
            'due_date' => $invoice->due_date === null ? null : Carbon::parse($invoice->due_date)->toDateString(),
            'comment' => 'Edited after Billing Run creation',
            'lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'amount' => (string) $line->amount,
                'subscription_id' => $line->subscription_id,
                'order_id' => $line->order_id,
                'period_start' => $line->period_start?->toDateString(),
                'period_end' => $line->period_end?->toDateString(),
            ]],
        ])->assertRedirect();

        $this->assertSame(2, CompanyActivityEvent::query()
            ->where('company_id', $invoice->company_id)
            ->count());
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::InvoiceUpdated->value,
            'subject_type' => 'invoice',
            'subject_id' => $invoice->id,
            'metadata->status' => 'draft',
        ]);
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::InvoiceIssued->value,
        ]);
    }

    public function test_draft_result_is_localized_in_az(): void
    {
        $subscription = $this->subscription(['title' => 'Xidmət']);
        $user = $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesView->value,
        ]);
        $this->withSession(['locale' => 'az'])->actingAs($user);

        $response = $this->post(route('invoices.billing.drafts', ['month' => 9, 'year' => 2026]), [
            'selected_occurrences' => [$this->previewIdentity($subscription)],
        ]);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Qaralamalar')
            ->assertSee('Yaradıldı')
            ->assertSee('Qaralama')
            ->assertSee('Aç');

        $this->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Qaralama')
            ->assertSee('data-row-url="'.htmlspecialchars(route('invoices.show', [
                'invoice' => Invoice::query()->sole()->id,
                'billing_preview' => 1,
                'month' => 9,
                'year' => 2026,
                'tab' => 'drafts',
            ]), ENT_QUOTES, 'UTF-8').'"', false);
    }

    public function test_billing_row_statuses_are_localized_in_az(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $this->subscription([
            'start_date' => '2026-09-07',
            'next_billing_date' => '2026-09-07',
        ]);
        $this->subscription([
            'start_date' => '2026-09-13',
            'next_billing_date' => '2026-09-13',
        ]);
        $this->subscription([
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $user = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->withSession(['locale' => 'az'])
            ->actingAs($user)
            ->get(route('invoices.billing.preview', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Rəsmiləşdirilə bilər')
            ->assertSee('13.09.2026 tarixindən rəsmiləşdirilə bilər')
            ->assertSee('Buraxılmış dövr')
            ->assertSeeText('01.08.2026 — 31.08.2026')
            ->assertSeeText('avqust 2026 dövrünə keç');
    }

    private function organization(): Organization
    {
        return Organization::query()->where('singleton_key', Organization::SINGLETON_KEY)->sole();
    }

    private function previewIdentity(Subscription $subscription): string
    {
        $row = collect(app(BillingPeriodPreview::class)
            ->forPeriod(Carbon::parse('2026-09-01')->toImmutable())['rows'])
            ->firstWhere('subscription_id', $subscription->id);

        $this->assertNotNull($row);

        return $row['identity'];
    }

    private function occurrenceKey(Subscription $subscription): string
    {
        return $this->occurrenceKeyFor($subscription, '2026-09-01', '2026-09-30');
    }

    private function occurrenceKeyFor(Subscription $subscription, string $start, string $end): string
    {
        return app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscription->id,
            Carbon::parse($start)->toImmutable(),
            Carbon::parse($end)->toImmutable(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function subscription(array $overrides = []): Subscription
    {
        $organization = $this->organization();
        $organization->update([
            'invoice_number_code' => 'TST',
            'is_vat_payer' => true,
            'vat_rate' => '19.00',
        ]);

        $company = Company::query()->create([
            'name' => 'Тестовая компания',
            'status' => 'active',
        ]);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $organization->id,
            'contract_number' => 'CTR-2026-001-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $service = ServiceType::query()->create([
            'name' => 'Мобильное приложение',
            'base_price' => '200.00',
            'type' => 'subscription',
        ]);

        return $contract->subscriptions()->forceCreate([
            'service_type_id' => $service->id,
            'title' => 'Мобильное приложение',
            'start_date' => '2026-09-01',
            'next_billing_date' => '2026-09-01',
            'billing_period' => 'monthly',
            'amount' => '200.00',
            'payment_terms' => 14,
            'status' => 'active',
            ...$overrides,
        ]);
    }
}
