<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Access\SystemRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardFinancialPeriodTest extends FinancialTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->assignRole(SystemRole::Administrator->value);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_current_debt_and_overdue_are_invariant_across_periods(): void
    {
        $company = $this->company('Current state invariants');
        $invoice = $this->invoice($company, '125.00', '2026-08-01', '2026-08-01');

        $this->payment($invoice, '25.00', '2026-09-05');

        $thisMonth = $this->get(route('dashboard'))->assertOk()->viewData('overview');
        $allTime = $this->get(route('dashboard', ['period' => 'all']))->assertOk()->viewData('overview');

        foreach (['total_debt', 'overdue_count', 'overdue_amount'] as $key) {
            $this->assertSame($thisMonth[$key], $allTime[$key], $key.' must not depend on period');
        }

        $this->assertSame('100.00', number_format((float) $thisMonth['total_debt'], 2, '.', ''));
        $this->assertSame(1, $thisMonth['overdue_count']);
        $this->assertSame('100.00', number_format((float) $thisMonth['overdue_amount'], 2, '.', ''));
    }

    public function test_invoice_and_confirmed_payment_totals_follow_each_period_preset(): void
    {
        $company = $this->company('Preset periods');
        $fixtures = [
            ['date' => '2026-09-05', 'amount' => '100.00'],
            ['date' => '2026-08-05', 'amount' => '200.00'],
            ['date' => '2026-06-05', 'amount' => '300.00'],
            ['date' => '2026-01-05', 'amount' => '400.00'],
            ['date' => '2025-01-05', 'amount' => '500.00'],
        ];

        foreach ($fixtures as $fixture) {
            $invoice = $this->invoice($company, $fixture['amount'], $fixture['date']);
            $this->payment($invoice, $fixture['amount'], $fixture['date']);
        }

        $expected = [
            'this_month' => '100.00',
            '3m' => '300.00',
            '6m' => '600.00',
            '1y' => '1000.00',
            'all' => '1500.00',
        ];

        foreach ($expected as $period => $amount) {
            $overview = $this->get(route('dashboard', ['period' => $period]))
                ->assertOk()
                ->viewData('overview');

            $this->assertSame($amount, number_format((float) $overview['total_invoiced'], 2, '.', ''), $period.' invoiced');
            $this->assertSame($amount, number_format((float) $overview['total_paid'], 2, '.', ''), $period.' paid');
        }
    }

    public function test_custom_period_filters_invoice_issue_and_payment_dates(): void
    {
        $company = $this->company('Custom period');
        foreach ([
            ['date' => '2026-05-10', 'amount' => '50.00'],
            ['date' => '2026-06-10', 'amount' => '75.00'],
            ['date' => '2026-08-10', 'amount' => '125.00'],
            ['date' => '2026-09-10', 'amount' => '200.00'],
        ] as $fixture) {
            $invoice = $this->invoice($company, $fixture['amount'], $fixture['date']);
            $this->payment($invoice, $fixture['amount'], $fixture['date']);
        }

        $response = $this->get(route('dashboard', [
            'period' => 'custom',
            'date_from' => '2026-06-01',
            'date_to' => '2026-08-31',
        ]))->assertOk();
        $overview = $response->viewData('overview');

        $this->assertSame('200.00', number_format((float) $overview['total_invoiced'], 2, '.', ''));
        $this->assertSame('200.00', number_format((float) $overview['total_paid'], 2, '.', ''));
        $this->assertSame('custom', $response->viewData('period')['key']);
    }

    public function test_default_query_contract_and_compact_period_selector_render(): void
    {
        $response = $this->get(route('dashboard'))->assertOk();
        $content = $response->getContent();

        $this->assertSame('this_month', $response->viewData('period')['key']);
        $this->assertStringContainsString('data-testid="dashboard-period-trigger"', $content);
        $this->assertStringContainsString('aria-haspopup="menu"', $content);
        $this->assertStringContainsString('x-bind:aria-expanded="(open || customOpen).toString()"', $content);
        $this->assertStringContainsString('x-on:keydown.escape.window', $content);
        $this->assertStringContainsString('data-testid="dashboard-period-active-label">Этот месяц', $content);
        $this->assertStringContainsString('data-testid="dashboard-period-menu"', $content);
        $this->assertStringContainsString('data-testid="dashboard-period-option-this_month"', $content);
        $this->assertStringNotContainsString('data-testid="dashboard-period-row"', $content);
        foreach (['this_month', '3m', '6m', '1y', 'all'] as $periodKey) {
            $this->assertStringContainsString(
                'href="'.htmlspecialchars(route('dashboard', ['period' => $periodKey]), ENT_QUOTES, 'UTF-8').'"',
                $content,
                $periodKey.' menu URL',
            );
        }
        $this->assertMatchesRegularExpression(
            '/data-testid="dashboard-period-selector"[^>]*class="[^"]*relative[^\"]*shrink-0/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="dashboard-period-trigger"[^>]*class="[^"]*border(?:-|\s)/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="dashboard-period-trigger"[^>]*class="[^"]*(?<!:)bg-/',
            $content,
        );
        $this->assertStringContainsString('w-44', $content);

        $selected = $this->get(route('dashboard', ['period' => '6m']))->assertOk();
        $this->assertSame('6m', $selected->viewData('period')['key']);
        $this->assertStringContainsString(
            'data-testid="dashboard-period-active-label">6 месяцев',
            $selected->getContent(),
        );
    }

    public function test_custom_selector_preserves_get_dates_and_opens_inline_area(): void
    {
        $response = $this->get(route('dashboard', [
            'period' => 'custom',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]))->assertOk();
        $content = $response->getContent();

        $this->assertSame('custom', $response->viewData('period')['key']);
        $this->assertStringContainsString('data-testid="dashboard-period-option-custom"', $content);
        $this->assertStringContainsString('data-testid="dashboard-period-custom-panel"', $content);
        $this->assertStringContainsString('data-testid="dashboard-period-active-label">Свой период', $content);
        $this->assertStringContainsString('name="date_from"', $content);
        $this->assertStringContainsString('name="date_to"', $content);
        $this->assertStringNotContainsString('data-testid="dashboard-period-row"', $content);
    }

    private function company(string $name): Company
    {
        return Company::query()->create(['name' => $name, 'status' => 'active']);
    }

    private function invoice(Company $company, string $amount, string $issueDate, string $dueDate = '2099-12-31'): Invoice
    {
        return $company->invoices()->create([
            'invoice_number' => 'DASH-PERIOD-'.uniqid(),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'status' => 'issued',
        ]);
    }

    private function payment(Invoice $invoice, string $amount, string $paymentDate): Payment
    {
        return Payment::withoutEvents(fn (): Payment => $invoice->payments()->create([
            'company_id' => $invoice->company_id,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => 'confirmed',
        ]));
    }
}
