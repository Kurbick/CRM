<?php

namespace Tests\Feature;

use App\Models\Company;
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

        $this->assertSame(1, $response->viewData('preview')['count']);
        $this->assertSame('01/10/2026 — 31/10/2026', $response->viewData('preview')['rows'][0]['period']);

        $subscription->contract->update(['end_date' => '2026-10-30']);
        $this->get(route('invoices.billing.preview', ['month' => 10, 'year' => 2026]))
            ->assertOk()
            ->assertSee('За октябрь 2026 счетов к выставлению нет.');
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

        $this->assertSame(3, $preview['count']);
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
            ]), ENT_QUOTES, 'UTF-8').'"', false);
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
