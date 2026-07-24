<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthenticatedTestCase as TestCase;

class InvoicePaymentSourceDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_payment_has_no_source_marker_on_index_or_show(): void
    {
        [$invoice, $lineId] = $this->invoice('500.00', 'paid');
        $payment = $this->payment($invoice, '500.00');
        $this->allocation($payment, $lineId, '500.00');

        $this->get(route('invoices.index'))->assertOk()
            ->assertDontSee('Из баланса:')
            ->assertDontSee('Частично из баланса');
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertDontSee('Из баланса:')
            ->assertDontSee('Частично из баланса');
    }

    public function test_full_balance_source_is_shown_on_index_totals_card_and_payment(): void
    {
        [$invoice, $lineId] = $this->invoice('500.00', 'paid');
        $payment = $this->payment($invoice, '500.00');
        $this->allocation($payment, $lineId, '500.00');
        $this->appliedEntry($invoice, $payment);

        $this->get(route('invoices.index'))->assertOk()->assertSee('Из баланса: 500,00 ₼');
        $response = $this->get(route('invoices.show', $invoice))->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'Из баланса: 500,00 ₼'));
        $this->assertSame(3, substr_count($response->getContent(), 'Из баланса'));
        $response->assertDontSee('Частично из баланса')
            ->assertDontSee('Оплата из Credit Balance');
    }

    public function test_mixed_source_is_separate_from_last_normal_payment(): void
    {
        [$invoice, $lineId] = $this->invoice('500.00', 'paid');
        $creditPayment = $this->payment($invoice, '20.00', '2026-07-20');
        $normalPayment = $this->payment($invoice, '480.00', '2026-07-21');
        $this->allocation($creditPayment, $lineId, '20.00');
        $this->allocation($normalPayment, $lineId, '480.00');
        $this->appliedEntry($invoice, $creditPayment);

        $this->get(route('invoices.index'))->assertOk()->assertSee('Из баланса: 20,00 ₼');
        $response = $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('480,00 ₼')
            ->assertSee('Из баланса: 20,00 ₼')
            ->assertDontSee('Частично из баланса');

        $content = $response->getContent();
        $this->assertStringContainsString('Последний платёж:', $content);
        $this->assertLessThan(strpos($content, '480,00 ₼'), strpos($content, 'Из баланса: 20,00 ₼'));
        $this->assertSame(2, substr_count($content, 'Из баланса: 20,00 ₼'));
        $this->assertSame(3, substr_count($content, 'Из баланса'));
    }

    public function test_only_applied_balance_money_is_full_even_with_remaining_invoice_debt(): void
    {
        [$invoice, $lineId] = $this->invoice('500.00', 'partially_paid');
        $payment = $this->payment($invoice, '100.00');
        $this->allocation($payment, $lineId, '100.00');
        $this->appliedEntry($invoice, $payment);

        $this->get(route('invoices.index'))->assertOk()
            ->assertSee('Из баланса: 100,00 ₼');
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Из баланса: 100,00 ₼')
            ->assertSee('400,00 ₼')
            ->assertDontSee('Частично из баланса');
    }

    public function test_index_source_state_does_not_add_queries_per_invoice(): void
    {
        $this->invoice('10.00', 'issued');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('invoices.index'))->assertOk();
        $singleInvoiceQueries = count(DB::getQueryLog());

        DB::disableQueryLog();
        for ($number = 0; $number < 9; $number++) {
            $this->invoice('10.00', 'issued');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('invoices.index'))->assertOk();
        $pageOfInvoicesQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleInvoiceQueries, $pageOfInvoicesQueries);
    }

    /** @return array{Invoice, int} */
    private function invoice(string $total, string $status): array
    {
        $suffix = uniqid();
        $companyId = DB::table('companies')->insertGetId(['name' => 'Company '.$suffix]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
        ]);
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'SOURCE-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoice->id,
            'description' => 'Саппорт',
            'amount' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$invoice, $lineId];
    }

    private function payment(Invoice $invoice, string $amount, string $date = '2026-07-20'): Payment
    {
        return Payment::withoutEvents(fn() => Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => 'confirmed',
        ]));
    }

    private function allocation(Payment $payment, int $lineId, string $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_line_id' => $lineId,
            'amount' => $amount,
        ]);
    }

    private function appliedEntry(Invoice $invoice, Payment $payment): void
    {
        $creditBalanceId = DB::table('credit_balances')->insertGetId([
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('credit_balance_entries')->insert([
            'credit_balance_id' => $creditBalanceId,
            'type' => 'applied',
            'amount' => $payment->amount,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
