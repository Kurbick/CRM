<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthenticatedTestCase as TestCase;

class CompanyFinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_and_cancelled_invoices_are_excluded(): void
    {
        $company = $this->company();
        $this->invoice($company, 'draft', 100);
        $cancelled = $this->invoice($company, 'cancelled', 100);
        $this->payment($cancelled, 'confirmed', 75);

        $this->assertSummary($company, 0, 0, 0);
    }

    public function test_issued_invoice_without_payments_is_fully_outstanding(): void
    {
        $company = $this->company();
        $this->invoice($company, 'issued', 100);

        $this->assertSummary($company, 100, 0, 100);
    }

    public function test_only_confirmed_payments_reduce_debt(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company, 'partially_paid', 100);
        $this->payment($invoice, 'confirmed', 40);
        $this->payment($invoice, 'pending', 30);

        $this->assertSummary($company, 100, 40, 60);
    }

    public function test_confirmed_credit_balance_payment_reduces_debt_regardless_of_comment(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company, 'partially_paid', 100);
        $this->payment($invoice, 'confirmed', 30, 'Автоматически оплачено из Credit Balance');

        $this->assertSummary($company, 100, 30, 70);
    }

    public function test_overpayment_is_capped_and_remaining_debt_never_becomes_negative(): void
    {
        $company = $this->company();
        $invoice = $this->invoice($company, 'paid', 100);
        $this->payment($invoice, 'confirmed', 110);
        $company->creditBalance()->create(['amount' => 10]);

        $summary = $this->showSummary($company);

        $this->assertSame(100.0, $summary['total_invoiced']);
        $this->assertSame(100.0, $summary['total_paid']);
        $this->assertSame(0.0, $summary['total_debt']);
        $this->assertSame(10.0, $summary['credit_balance']);
    }

    public function test_multiple_invoices_are_summed_by_their_individual_applied_and_remaining_amounts(): void
    {
        $company = $this->company();
        $first = $this->invoice($company, 'partially_paid', 100);
        $second = $this->invoice($company, 'paid', 80);
        $this->payment($first, 'confirmed', 40);
        $this->payment($second, 'confirmed', 100);

        $this->assertSummary($company, 180, 120, 60);
    }

    public function test_companies_are_isolated_and_index_matches_show(): void
    {
        $firstCompany = $this->company('First Company');
        $secondCompany = $this->company('Second Company');
        $firstInvoice = $this->invoice($firstCompany, 'partially_paid', 100);
        $secondInvoice = $this->invoice($secondCompany, 'paid', 500);
        $this->payment($firstInvoice, 'confirmed', 25);
        $this->payment($secondInvoice, 'confirmed', 500);

        $showSummary = $this->showSummary($firstCompany);
        $indexCompany = $this->get(route('companies.index'))
            ->assertOk()
            ->viewData('companies')
            ->getCollection()
            ->firstWhere('id', $firstCompany->id);

        $this->assertNotNull($indexCompany);
        $this->assertSame(100.0, (float) $indexCompany->total_invoiced);
        $this->assertSame(25.0, (float) $indexCompany->total_paid);
        $this->assertSame(75.0, (float) $indexCompany->total_debt);
        $this->assertSame($showSummary['total_invoiced'], (float) $indexCompany->total_invoiced);
        $this->assertSame($showSummary['total_paid'], (float) $indexCompany->total_paid);
        $this->assertSame($showSummary['total_debt'], (float) $indexCompany->total_debt);
    }

    private function assertSummary(
        Company $company,
        float $totalInvoiced,
        float $totalPaid,
        float $totalDebt
    ): void {
        $summary = $this->showSummary($company);

        $this->assertSame($totalInvoiced, $summary['total_invoiced']);
        $this->assertSame($totalPaid, $summary['total_paid']);
        $this->assertSame($totalDebt, $summary['total_debt']);
    }

    private function showSummary(Company $company): array
    {
        return $this->get(route('companies.show', $company))
            ->assertOk()
            ->viewData('stats');
    }

    private function company(?string $name = null): Company
    {
        return Company::create([
            'name' => $name ?? 'Company '.uniqid(),
        ]);
    }

    private function invoice(Company $company, string $status, float $total): Invoice
    {
        return Invoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
    }

    private function payment(
        Invoice $invoice,
        string $status,
        float $amount,
        ?string $comment = null
    ): Payment {
        return Payment::withoutEvents(fn() => Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-21',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]));
    }
}
