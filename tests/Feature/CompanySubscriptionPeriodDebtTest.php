<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthenticatedTestCase as TestCase;

class CompanySubscriptionPeriodDebtTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_without_invoice_lines_shows_empty_period_state(): void
    {
        $company = $this->company('Empty Company');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('Финансы')
            ->assertDontSee('Финансовое состояние')
            ->assertSee('Выставлено')
            ->assertSee('Оплачено')
            ->assertSee('Общий долг')
            ->assertSee('Просрочено')
            ->assertDontSee('Неоплаченных периодов')
            ->assertDontSee('Всего выставлено')
            ->assertSee('Задолженности')
            ->assertDontSee('Задолженность по периодам')
            ->assertSee('У компании нет задолженности.');
    }

    public function test_zero_and_positive_overdue_use_neutral_and_warning_summary_styles(): void
    {
        $company = $this->company();
        $neutral = $this->get(route('companies.show', $company))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-testid="overdue-summary"\s+class="[^"]*bg-gray-50/',
            $neutral
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="overdue-summary"\s+class="[^"]*bg-red-50/',
            $neutral
        );

        $this->period($company, dueDate: '2020-01-01');
        $warning = $this->get(route('companies.show', $company))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-testid="overdue-summary"\s+class="[^"]*bg-red-50/',
            $warning
        );
    }

    public function test_main_information_hides_empty_fields_and_embeds_only_present_bank_details(): void
    {
        $empty = $this->company('Empty Details');

        $this->get(route('companies.show', $empty))
            ->assertOk()
            ->assertSee('Основная информация')
            ->assertDontSee('Детали контрагента')
            ->assertDontSee('data-testid="company-voen"', false)
            ->assertDontSee('data-testid="company-email"', false)
            ->assertDontSee('data-testid="company-phone"', false)
            ->assertDontSee('data-testid="company-legal-address"', false)
            ->assertDontSee('data-testid="company-actual-address"', false)
            ->assertDontSee('data-testid="company-bank-details"', false);

        $filled = $this->company('Filled Details', [
            'voen' => '1234567890',
            'email' => 'company@example.test',
            'phone' => '+994501112233',
            'website' => 'https://example.test',
            'legal_address' => 'Юридический адрес 1',
            'actual_address' => 'Фактический адрес 2',
            'bank_name' => 'Test Bank',
            'iban' => 'AZ00TEST00000000000000000000',
        ]);

        $this->get(route('companies.show', $filled))
            ->assertOk()
            ->assertSee('data-testid="company-voen"', false)
            ->assertSee('data-testid="company-email"', false)
            ->assertSee('data-testid="company-phone"', false)
            ->assertSee('data-testid="company-legal-address"', false)
            ->assertSee('data-testid="company-actual-address"', false)
            ->assertSee('data-testid="company-bank-details"', false)
            ->assertSee('VÖEN (ИНН)')
            ->assertSee('1234567890')
            ->assertSee('company@example.test')
            ->assertSee('+994501112233')
            ->assertSee('Юридический адрес 1')
            ->assertSee('Фактический адрес 2')
            ->assertSee('Банковские реквизиты')
            ->assertSee('Test Bank')
            ->assertSee('AZ00TEST00000000000000000000');
    }

    public function test_header_places_feminine_status_next_to_company_name_and_type_below(): void
    {
        $company = $this->company('SkyCell');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSeeInOrder(['SkyCell', 'data-testid="company-status"', 'Активна', 'Юридическое лицо'], false);
    }

    public function test_one_time_debt_remains_visible_when_subscription_is_fully_paid(): void
    {
        $company = $this->company();
        $period = $this->period($company, invoiceStatus: 'paid');
        $this->allocate($period, '100.00', 'confirmed');
        $oneTime = $this->manualLine($company, 'Разработка сайта', '1200.00', '2099-08-20');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('1,300.00 ₼')
            ->assertSee('100.00 ₼')
            ->assertSee('1,200.00 ₼')
            ->assertSee('Задолженностей нет.')
            ->assertDontSee('По подпискам задолженности нет.')
            ->assertDontSee('По разовым услугам задолженности нет.')
            ->assertDontSee('Все выставленные периоды оплачены.')
            ->assertSee('По разовым услугам')
            ->assertSee('Разработка сайта')
            ->assertDontSee('У компании нет задолженности.')
            ->assertSee(route('invoices.show', ['invoice' => $oneTime['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']));
    }

    #[DataProvider('companyTabs')]
    public function test_query_parameter_initializes_only_whitelisted_company_tabs(string $query, string $expected): void
    {
        $company = $this->company();
        $this->get(route('companies.show', ['company' => $company, 'tab' => $query]))
            ->assertOk()
            ->assertSee("tab: '{$expected}'", false);
    }

    public static function companyTabs(): array
    {
        return [['contracts', 'contracts'], ['invoices', 'invoices'], ['payments', 'payments'], ['invalid', 'contacts']];
    }

    public function test_tabs_and_contextual_company_routes_are_rendered_without_extra_actions(): void
    {
        $company = $this->company();

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('Контакты (0)')
            ->assertSee('Договоры (0)')
            ->assertSee('Инвойсы (0)')
            ->assertSee('Платежи (0)')
            ->assertSee('+</span> Контакт', false)
            ->assertSee('+</span> Договор', false)
            ->assertSee(route('companies.contacts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contacts']))
            ->assertSee(route('companies.contracts.create', ['company' => $company, 'origin' => 'company', 'tab' => 'contracts']))
            ->assertSee('x-show="tab === \'contacts\'"', false)
            ->assertSee('x-show="tab === \'contracts\'"', false)
            ->assertDontSee('+ Добавить контакт')
            ->assertDontSee('+ Добавить договор')
            ->assertSee('Контакт')
            ->assertDontSee('Имя / Должность')
            ->assertSee('Телефон и e-mail')
            ->assertSee('Контакты отсутствуют.')
            ->assertSee('Добавьте контактное лицо компании.')
            ->assertSee('У компании пока нет договоров.')
            ->assertSee('У компании пока нет инвойсов.')
            ->assertSee('Платежи отсутствуют.');
    }

    public function test_company_contracts_are_regular_contracts_linked_to_contract_show_and_scoped(): void
    {
        $company = $this->company('Target');
        $other = $this->company('Other');
        $contractId = $this->contract($company, 'TARGET-CONTRACT');
        $this->contract($other, 'OTHER-CONTRACT');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('TARGET-CONTRACT')
            ->assertSee(route('contracts.show', ['contract' => $contractId, 'origin' => 'company', 'tab' => 'contracts']))
            ->assertDontSee('OTHER-CONTRACT')
            ->assertSee('Предметы договора');
    }

    public function test_fully_paid_period_shows_paid_empty_state_without_debt_row(): void
    {
        $company = $this->company();
        $period = $this->period($company, invoiceStatus: 'paid');
        $this->allocate($period, '100.00', 'confirmed');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('У компании нет задолженности.')
            ->assertDontSee('К оплате');
    }

    public function test_unpaid_issued_period_shows_totals_status_and_invoice_link(): void
    {
        $company = $this->company();
        $period = $this->period($company, dueDate: '2099-05-11');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('100.00 ₼')
            ->assertSee('К оплате')
            ->assertSee('По подпискам')
            ->assertSee('INV-DEBT-1')
            ->assertSee(route('invoices.show', ['invoice' => $period['invoice_id'], 'origin' => 'company', 'tab' => 'invoices']));
    }

    public function test_partial_confirmed_allocation_shows_allocated_remaining_and_partial_status(): void
    {
        $company = $this->company();
        $period = $this->period($company, dueDate: '2099-05-11');
        $this->allocate($period, '30.00', 'confirmed');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('30.00 ₼')
            ->assertSee('70.00 ₼')
            ->assertSee('Частично оплачено');
    }

    public function test_overdue_period_shows_overdue_badge_days_and_summary(): void
    {
        $company = $this->company();
        $this->period($company, dueDate: '2020-01-01');

        $response = $this->get(route('companies.show', $company))->assertOk();

        $response->assertSee('Просрочено на');
        $response->assertSee('дн.');
        $response->assertSee('Просрочено');
    }

    public function test_pending_and_cancelled_payments_do_not_reduce_debt(): void
    {
        $company = $this->company();
        $pendingPeriod = $this->period(
            $company,
            invoiceNumber: 'INV-PENDING',
            subscriptionTitle: 'Pending subscription',
            dueDate: '2099-05-11'
        );
        $cancelledPeriod = $this->period(
            $company,
            invoiceNumber: 'INV-CANCELLED-PAYMENT',
            subscriptionTitle: 'Cancelled payment subscription',
            dueDate: '2099-05-11'
        );
        $this->allocate($pendingPeriod, '40.00', 'pending');
        $this->allocate($cancelledPeriod, '50.00', 'cancelled');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('200.00 ₼')
            ->assertSee('Pending subscription')
            ->assertSee('Cancelled payment subscription');
    }

    public function test_confirmed_credit_balance_payment_reduces_debt_without_comment_filtering(): void
    {
        $company = $this->company();
        $period = $this->period($company, dueDate: '2099-05-11');
        $this->allocate(
            $period,
            '25.00',
            'confirmed',
            'Автоматически применён Credit Balance'
        );

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('25.00 ₼')
            ->assertSee('75.00 ₼')
            ->assertSee('Частично оплачено');
    }

    public function test_draft_cancelled_and_other_company_lines_are_excluded_but_manual_line_is_detailed(): void
    {
        $company = $this->company('Target Company');
        $other = $this->company('Other Company');
        $this->period($company, invoiceStatus: 'draft', subscriptionTitle: 'Draft hidden');
        $this->period($company, invoiceStatus: 'cancelled', subscriptionTitle: 'Cancelled hidden');
        $this->manualLine($company, 'Manual hidden');
        $this->period($other, subscriptionTitle: 'Other company hidden');

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('Задолженностей нет.')
            ->assertDontSee('По подпискам задолженности нет.')
            ->assertDontSee('По разовым услугам задолженности нет.')
            ->assertDontSee('Все выставленные периоды оплачены.')
            ->assertSee('По разовым услугам')
            ->assertSee('Manual hidden')
            ->assertDontSee('Draft hidden')
            ->assertDontSee('Cancelled hidden')
            ->assertDontSee('Other company hidden');
    }

    public function test_multiple_subscriptions_and_periods_are_grouped_and_sorted(): void
    {
        $company = $this->company();
        $this->period(
            $company,
            invoiceNumber: 'INV-BETA',
            subscriptionTitle: 'Бета',
            periodStart: '2026-06-01',
            periodEnd: '2026-06-30'
        );
        $alphaLate = $this->period(
            $company,
            invoiceNumber: 'INV-ALPHA-LATE',
            subscriptionTitle: 'Альфа',
            periodStart: '2026-05-01',
            periodEnd: '2026-05-31'
        );
        $this->period(
            $company,
            invoiceNumber: 'INV-ALPHA-EARLY',
            subscriptionTitle: 'Альфа',
            periodStart: '2026-04-01',
            periodEnd: '2026-04-30',
            subscriptionId: $alphaLate['subscription_id'],
        );

        $response = $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('Альфа')
            ->assertSee('Бета')
            ->assertSeeInOrder(['INV-ALPHA-EARLY', 'INV-ALPHA-LATE', 'INV-BETA']);

        $this->assertSame(
            1,
            substr_count($response->getContent(), '>Альфа</h3>')
        );
    }

    public function test_missing_period_metadata_shows_compact_anomaly_warning(): void
    {
        $company = $this->company();
        $this->period($company, periodStart: null, periodEnd: null);

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertDontSee('У компании нет задолженности.')
            ->assertSee('Есть строки подписок без корректно указанного расчётного периода: 1.')
            ->assertDontSee('missing_period_start');
    }

    public function test_debt_tables_use_consistent_left_alignment(): void
    {
        $view = file_get_contents(resource_path('views/companies/show.blade.php'));
        preg_match_all('/<table class="w-full min-w-\[940px\].*?<\/table>/s', $view, $matches);

        $this->assertCount(2, $matches[0]);
        foreach ($matches[0] as $table) {
            $this->assertStringNotContainsString('text-right', $table);
            $this->assertStringNotContainsString('text-center', $table);
            $this->assertMatchesRegularExpression('/<th class="[^"]*text-left/', $table);
            $this->assertMatchesRegularExpression('/<td class="[^"]*text-left/', $table);
            $this->assertMatchesRegularExpression('/<th class="[^"]*text-left[^"]*">Статус<\/th>/', $table);
            $this->assertMatchesRegularExpression('/<td class="[^"]*text-left[^"]*tabular-nums/', $table);
        }
    }

    private function company(string $name = 'Debt Company', array $attributes = []): Company
    {
        return Company::create([
            'name' => $name,
            'status' => 'active',
            'invoice_mode' => 'separate',
            ...$attributes,
        ]);
    }

    private function contract(Company $company, string $number): int
    {
        return DB::table('contracts')->insertGetId([
            'company_id' => $company->id,
            'contract_number' => $number,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    /** @return array{invoice_id: int, invoice_line_id: int, company_id: int, subscription_id: int} */
    private function period(
        Company $company,
        string $invoiceStatus = 'issued',
        string $invoiceNumber = 'INV-DEBT-1',
        string $subscriptionTitle = 'Техническая поддержка',
        ?string $periodStart = '2026-05-01',
        ?string $periodEnd = '2026-05-31',
        ?string $dueDate = '2026-05-11',
        ?int $subscriptionId = null,
    ): array {
        $suffix = uniqid('', true);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $company->id,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $subscriptionId ??= DB::table('subscriptions')->insertGetId([
                'contract_id' => $contractId,
                'service_type_id' => null,
                'title' => $subscriptionTitle,
                'start_date' => '2026-01-01',
                'next_billing_date' => '2026-06-01',
                'billing_period' => 'monthly',
                'amount' => '100.00',
                'status' => 'active',
            ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $company->id,
            'contract_id' => $contractId,
            'invoice_number' => $invoiceNumber.'-'.$suffix,
            'issue_date' => '2026-05-01',
            'due_date' => $dueDate,
            'total_amount' => '100.00',
            'status' => $invoiceStatus,
        ]);
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'subscription_id' => $subscriptionId,
            'description' => $subscriptionTitle,
            'amount' => '100.00',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return [
            'invoice_id' => $invoiceId,
            'invoice_line_id' => $lineId,
            'company_id' => $company->id,
            'subscription_id' => $subscriptionId,
        ];
    }

    private function manualLine(Company $company, string $description, string $amount = '100.00', ?string $dueDate = '2026-05-11'): array
    {
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $company->id,
            'invoice_number' => 'INV-MANUAL-'.uniqid(),
            'issue_date' => '2026-05-01',
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'status' => 'issued',
        ]);
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'subscription_id' => null,
            'order_id' => null,
            'description' => $description,
            'amount' => $amount,
        ]);

        return ['invoice_id' => $invoiceId, 'invoice_line_id' => $lineId, 'company_id' => $company->id];
    }

    private function allocate(
        array $period,
        string $amount,
        string $status,
        ?string $comment = null
    ): void {
        $paymentId = DB::table('payments')->insertGetId([
            'invoice_id' => $period['invoice_id'],
            'company_id' => $period['company_id'],
            'payment_date' => '2026-05-10',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]);
        DB::table('payment_allocations')->insert([
            'payment_id' => $paymentId,
            'invoice_line_id' => $period['invoice_line_id'],
            'amount' => $amount,
        ]);
    }
}
