<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePendingPaymentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_form_uses_available_amount_and_keeps_actual_invoice_totals(): void
    {
        $invoice = $this->invoice('600.00');
        $this->payment($invoice, 'pending', '500.00');

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="100.00"', false)
            ->assertSee('Остаток к оплате:')
            ->assertSee('100,00 ₼')
            ->assertSee('Оплачено:')
            ->assertSee('0,00 ₼')
            ->assertSee('600,00 ₼');

        $invoice->refresh()->load('payments');
        $this->assertSame(0.0, $invoice->paid_amount);
        $this->assertSame(600.0, $invoice->remaining_amount);
    }

    public function test_multiple_pending_are_summed_and_cancelled_is_ignored(): void
    {
        $invoice = $this->invoice('600.00');
        $this->payment($invoice, 'pending', '300.00');
        $this->payment($invoice, 'pending', '200.00');
        $this->payment($invoice, 'cancelled', '500.00');

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="100.00"', false)
            ->assertSee('100,00 ₼');
    }

    public function test_confirmed_and_pending_amounts_are_kept_separate(): void
    {
        $invoice = $this->invoice('600.00', 'partially_paid');
        $confirmedPayment = $this->payment($invoice, 'confirmed', '200.00');
        DB::table('payment_allocations')->insert([
            'payment_id' => $confirmedPayment->id,
            'invoice_line_id' => $invoice->lines()->firstOrFail()->id,
            'amount' => '200.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->payment($invoice, 'pending', '300.00');

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="100.00"', false)
            ->assertSee('200,00 ₼')
            ->assertSee('400,00 ₼');
    }

    public function test_old_payment_amount_is_preserved_after_validation_error(): void
    {
        $invoice = $this->invoice('600.00');

        $this->withSession(['_old_input' => ['amount' => '77.77']])
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('value="77.77"', false);
    }

    public function test_stale_new_payment_above_available_is_rejected_under_invoice_lock(): void
    {
        $invoice = $this->invoice('600.00');
        $this->payment($invoice, 'pending', '500.00');

        $this->from(route('invoices.show', $invoice))
            ->post(route('payments.store', $invoice), $this->paymentPayload('600.00'))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHasErrors([
                'amount' => 'Сумма платежа не может превышать остаток 100,00 ₼.',
            ]);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_equal_to_available_is_created_and_zero_is_rejected(): void
    {
        $invoice = $this->invoice('600.00');
        $this->payment($invoice, 'pending', '500.00');

        $this->post(route('payments.store', $invoice), $this->paymentPayload('100.00', 'pending'))
            ->assertRedirect(route('invoices.show', $invoice));
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => '100.00',
            'status' => 'pending',
        ]);

        $this->post(route('payments.store', $invoice), $this->paymentPayload('0'))
            ->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_existing_pending_can_be_confirmed_without_availability_checking_it_against_itself(): void
    {
        $invoice = $this->invoice('600.00');
        $payment = $this->payment($invoice, 'pending', '500.00');

        $this->patch(route('payments.confirm', $payment))->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="100.00"', false)
            ->assertSee('100,00 ₼');
    }

    public function test_without_pending_existing_overpayment_remains_supported(): void
    {
        $invoice = $this->invoice('600.00');

        $this->post(route('payments.store', $invoice), $this->paymentPayload('700.00'))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => '700.00',
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'amount' => '100.00',
        ]);
    }

    private function invoice(string $total, string $status = 'issued'): Invoice
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
            'invoice_number' => 'PENDING-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
        $invoice->lines()->create(['description' => 'Service', 'amount' => $total]);

        return $invoice;
    }

    private function payment(Invoice $invoice, string $status, string $amount): Payment
    {
        return Payment::withoutEvents(fn() => Payment::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-20',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ]));
    }

    private function paymentPayload(string $amount, string $status = 'confirmed'): array
    {
        return [
            'payment_date' => '2026-07-20',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ];
    }
}
