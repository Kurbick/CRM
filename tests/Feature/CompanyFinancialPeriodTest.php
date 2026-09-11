<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CompanyFinancialPeriodTest extends CompanyFinancialTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_company_period_presets_are_scoped_and_current_debt_is_invariant(): void
    {
        $company = $this->company('Period company');
        $this->invoiceWithPayment($company, '100.00', '2026-09-05', '2026-09-05');
        $this->invoiceWithPayment($company, '200.00', '2026-08-05', '2026-08-05');
        $this->invoiceWithPayment($company, '300.00', '2026-06-05', '2026-06-05');
        $this->invoiceWithPayment($company, '400.00', '2026-01-05', '2026-01-05');
        $this->invoiceWithPayment($company, '500.00', '2025-01-05', '2025-01-05');
        $this->invoice($company, '150.00', '2026-01-10', 'issued', '2026-08-01');

        $otherCompany = $this->company('Other period company');
        $this->invoiceWithPayment($otherCompany, '900.00', '2026-09-05', '2026-09-05');

        $expectedInvoiced = [
            'this_month' => '100.00',
            '3m' => '300.00',
            '6m' => '600.00',
            '1y' => '1150.00',
            'all' => '1650.00',
        ];
        $expectedPaid = [
            'this_month' => '100.00',
            '3m' => '300.00',
            '6m' => '600.00',
            '1y' => '1000.00',
            'all' => '1500.00',
        ];
        $currentState = null;

        foreach ($expectedInvoiced as $period => $amount) {
            $response = $this->get(route('companies.show', ['company' => $company, 'period' => $period]))
                ->assertOk();
            $stats = $response->viewData('stats');

            $this->assertSame($amount, number_format((float) $stats['total_invoiced'], 2, '.', ''), $period.' invoiced');
            $this->assertSame($expectedPaid[$period], number_format((float) $stats['total_paid'], 2, '.', ''), $period.' paid');

            $state = [
                'total_debt' => $stats['total_debt'],
                'overdue' => $response->viewData('overdueRemaining'),
            ];
            $currentState ??= $state;
            $this->assertSame($currentState, $state, $period.' must not change current state');
        }

        $this->assertSame('150.00', number_format((float) $currentState['total_debt'], 2, '.', ''));
    }

    public function test_company_custom_period_uses_issue_and_confirmed_payment_dates(): void
    {
        $company = $this->company('Custom company period');
        $invoicePaidInPeriod = $this->invoice($company, '125.00', '2026-08-15', 'issued');
        $this->payment($invoicePaidInPeriod, '125.00', '2026-09-05', 'confirmed');

        $invoiceIssuedInPeriod = $this->invoice($company, '75.00', '2026-09-05', 'issued');
        $this->payment($invoiceIssuedInPeriod, '75.00', '2026-08-31', 'confirmed');

        $pendingInvoice = $this->invoice($company, '50.00', '2026-09-06', 'issued');
        $this->payment($pendingInvoice, '50.00', '2026-09-06', 'pending');

        $otherCompany = $this->company('Other custom company');
        $otherInvoice = $this->invoice($otherCompany, '900.00', '2026-09-05', 'issued');
        $this->payment($otherInvoice, '900.00', '2026-09-05', 'confirmed');

        $response = $this->get(route('companies.show', [
            'company' => $company,
            'period' => 'custom',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-10',
        ]))->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame('125.00', number_format((float) $stats['total_invoiced'], 2, '.', ''));
        $this->assertSame('125.00', number_format((float) $stats['total_paid'], 2, '.', ''));
        $this->assertSame('custom', $response->viewData('period')['key']);
    }

    public function test_company_period_selector_matches_dashboard_pattern_and_preserves_tab(): void
    {
        $company = $this->company('Selector company');
        $response = $this->get(route('companies.show', [
            'company' => $company,
            'tab' => 'invoices',
            'period' => '6m',
        ]))->assertOk();
        $content = $response->getContent();

        $this->assertSame('6m', $response->viewData('period')['key']);
        $this->assertStringContainsString('data-testid="company-period-trigger"', $content);
        $this->assertStringContainsString('aria-haspopup="menu"', $content);
        $this->assertStringContainsString('x-bind:aria-expanded="(open || customOpen).toString()"', $content);
        $this->assertStringContainsString('data-testid="company-period-active-label">6 месяцев', $content);
        $this->assertStringContainsString('data-testid="company-period-menu"', $content);
        $this->assertStringContainsString('data-testid="company-period-option-custom"', $content);
        $this->assertStringContainsString('data-testid="company-period-custom-panel"', $content);
        $this->assertStringNotContainsString('data-testid="company-period-row"', $content);

        foreach (['this_month', '3m', '6m', '1y', 'all'] as $periodKey) {
            $this->assertStringContainsString(
                'href="'.htmlspecialchars(route('companies.show', [
                    'company' => $company,
                    'tab' => 'invoices',
                    'period' => $periodKey,
                ]), ENT_QUOTES, 'UTF-8').'"',
                $content,
                $periodKey.' menu URL',
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="company-period-trigger"[^>]*class="[^"]*border(?:-|\s)/',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-testid="company-period-trigger"[^>]*class="[^"]*(?<!:)bg-/',
            $content,
        );
    }

    private function company(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function invoice(
        Company $company,
        string $amount,
        string $issueDate,
        string $status,
        string $dueDate = '2099-12-31',
    ): Invoice {
        return $company->invoices()->create([
            'invoice_number' => 'COMPANY-PERIOD-'.uniqid(),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'status' => $status,
        ]);
    }

    private function invoiceWithPayment(Company $company, string $amount, string $issueDate, string $paymentDate): Invoice
    {
        $invoice = $this->invoice($company, $amount, $issueDate, 'paid');
        $this->payment($invoice, $amount, $paymentDate, 'confirmed');

        return $invoice;
    }

    private function payment(Invoice $invoice, string $amount, string $paymentDate, string $status): Payment
    {
        return Payment::withoutEvents(fn (): Payment => $invoice->payments()->create([
            'company_id' => $invoice->company_id,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]));
    }
}
