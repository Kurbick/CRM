<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FinancialTestCase as TestCase;

class InvoicePaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payment_does_not_change_invoice_amounts_or_status(): void
    {
        $invoice = $this->invoice();

        $this->post(route('payments.store', $invoice), [
            'payment_date' => '2026-07-21',
            'amount' => 40,
            'payment_method' => 'transfer',
            'status' => 'pending',
        ])->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();

        $this->assertSame('issued', $invoice->status);
        $this->assertSame(0.0, $invoice->paid_amount);
        $this->assertSame(100.0, $invoice->remaining_amount);
    }

    public function test_pending_payment_can_be_confirmed_only_once(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, 'pending', 40);

        $this->patch(route('payments.confirm', $payment))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'partially_paid',
        ]);

        $this->patch(route('payments.confirm', $payment))
            ->assertSessionHasErrors('payment_confirm');
    }

    public function test_pending_payment_cancellation_keeps_history_and_does_not_change_invoice(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, 'pending', 40);

        $cancelledAt = CarbonImmutable::create(2031, 2, 3, 8, 9, 10, 'UTC');
        Carbon::setTestNow($cancelledAt);
        try {
            $this->patch(route('payments.cancel', $payment), [
                'cancel_payment_id' => $payment->id,
                'cancel_reason' => 'Платёж создан ошибочно',
            ])->assertRedirect(route('invoices.show', $invoice));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'cancelled',
            'cancel_reason' => 'Платёж создан ошибочно',
        ]);
        $this->assertSame(
            $cancelledAt->getTimestamp(),
            (int) DB::selectOne('SELECT UNIX_TIMESTAMP(cancelled_at) AS epoch FROM payments WHERE id = ?', [$payment->id])->epoch,
        );
        $this->assertNotNull($payment->fresh()->cancelled_at);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->paid_amount);
    }

    public function test_pending_payment_cannot_be_cancelled_without_reason(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, 'pending', 40);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
        ])->assertSessionHasErrors('cancel_reason');

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->cancelled_at);
        $this->assertNull($payment->cancel_reason);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame(0.0, $invoice->paid_amount);
        $this->assertSame(100.0, $invoice->remaining_amount);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'payment_id' => $payment->id,
        ]);
    }

    public function test_confirmed_payment_cannot_be_cancelled_without_reason(): void
    {
        $invoice = $this->invoice();
        $payment = $this->confirmedPayment($invoice, 125);
        $balance = CreditBalance::where('company_id', $invoice->company_id)->firstOrFail();

        $this->assertSame(25.0, (float) $balance->amount);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
        ])->assertSessionHasErrors('cancel_reason');

        $payment->refresh();
        $invoice->refresh();
        $balance->refresh();

        $this->assertSame('confirmed', $payment->status);
        $this->assertNull($payment->cancelled_at);
        $this->assertNull($payment->cancel_reason);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(125.0, $invoice->paid_amount);
        $this->assertSame(25.0, (float) $balance->amount);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
        ]);
    }

    public function test_cancel_payment_id_must_match_route_payment(): void
    {
        $invoice = $this->invoice();
        $routePayment = $this->payment($invoice, 'pending', 40);
        $otherPayment = $this->payment($invoice, 'pending', 20);

        $this->patch(route('payments.cancel', $routePayment), [
            'cancel_payment_id' => $otherPayment->id,
            'cancel_reason' => 'Ошибочная форма платежа',
        ])->assertSessionHasErrors('cancel_payment_id');

        $this->assertSame('pending', $routePayment->fresh()->status);
        $this->assertSame('pending', $otherPayment->fresh()->status);
        $this->assertNull($routePayment->fresh()->cancelled_at);
        $this->assertNull($otherPayment->fresh()->cancelled_at);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_cancelling_confirmed_partial_payment_recalculates_invoice_status(): void
    {
        $invoice = $this->invoice();
        $payment = $this->confirmedPayment($invoice, 40);

        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Банковский платёж отозван',
        ])->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->paid_amount);
        $this->get(route('invoices.edit', $invoice))->assertOk();
    }

    public function test_cancelling_overpayment_creates_top_up_reversal(): void
    {
        $invoice = $this->invoice();
        $payment = $this->confirmedPayment($invoice, 125);

        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'amount' => 25,
        ]);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Возврат ошибочного платежа',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 25,
        ]);
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => 0,
        ]);
    }

    public function test_cancellation_is_blocked_when_overpayment_was_used(): void
    {
        $invoice = $this->invoice();
        $payment = $this->confirmedPayment($invoice, 125);
        $otherInvoice = $this->invoice($invoice->company_id, 'INV-OTHER');
        $result = app(ApplyCreditToInvoice::class)->execute($otherInvoice);

        $this->assertTrue($result->applied);
        $this->assertSame(2500, $result->appliedAmountMinor);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Попытка возврата переплаты',
        ])->assertSessionHasErrors('cancel_reason');

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertDatabaseMissing('credit_balance_entries', [
            'type' => 'top_up_reversal',
            'payment_id' => $payment->id,
        ]);
    }

    public function test_credit_balance_payment_cancellation_restores_credit(): void
    {
        $invoice = $this->invoice();
        $payment = Payment::create($this->paymentAttributes($invoice, 'confirmed', 50));
        $balance = CreditBalance::create([
            'company_id' => $invoice->company_id,
            'amount' => 0,
        ]);

        $balance->entries()->create([
            'type' => 'applied',
            'amount' => 50,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ]);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Возврат Credit Balance',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertDatabaseHas('credit_balances', [
            'id' => $balance->id,
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'credit_balance_id' => $balance->id,
            'type' => 'applied_reversal',
            'amount' => 50,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_cancelled_payment_cannot_be_cancelled_again(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, 'cancelled', 40, [
            'cancelled_at' => now(),
            'cancel_reason' => 'Уже отменён',
        ]);

        $this->patch(route('payments.cancel', $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Повторная отмена',
        ])->assertSessionHasErrors('cancel_reason');
    }

    public function test_invoice_money_accessors_separate_applied_and_overpayment_amounts(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 'confirmed', 125);
        $this->payment($invoice, 'pending', 500);

        $invoice->load('payments');

        $this->assertSame(125.0, $invoice->paid_amount);
        $this->assertSame(100.0, $invoice->applied_amount);
        $this->assertSame(25.0, $invoice->overpayment_amount);
        $this->assertSame(0.0, $invoice->remaining_amount);
    }

    private function invoice(?int $companyId = null, ?string $number = null): Invoice
    {
        $companyId ??= DB::table('companies')->insertGetId([
            'name' => 'Company '.uniqid(),
        ]);

        return Invoice::create([
            'company_id' => $companyId,
            'invoice_number' => $number ?? 'INV-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => 100,
            'status' => 'issued',
        ]);
    }

    private function payment(
        Invoice $invoice,
        string $status,
        float $amount,
        array $overrides = []
    ): Payment {
        return Payment::withoutEvents(fn () => Payment::create(array_merge(
            $this->paymentAttributes($invoice, $status, $amount),
            $overrides
        )));
    }

    private function paymentAttributes(
        Invoice $invoice,
        string $status,
        float $amount
    ): array {
        return [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-21',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
        ];
    }

    private function confirmedPayment(Invoice $invoice, float $amount): Payment
    {
        return app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => '2026-07-21',
            'amount' => (string) $amount,
            'payment_method' => 'transfer',
        ]);
    }
}
