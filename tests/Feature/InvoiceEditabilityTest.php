<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\AuthenticatedTestCase as TestCase;

class InvoiceEditabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_issued_and_overdue_issued_can_open_edit_and_show_edit_action(): void
    {
        foreach ([
            $this->invoice('draft'),
            $this->invoice('issued'),
            $this->invoice('issued', dueDate: '2020-01-01'),
        ] as $invoice) {
            $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Редактировать');
            $this->get(route('invoices.edit', $invoice))->assertOk()->assertSee('Редактировать инвойс');
        }
    }

    public function test_pending_payment_keeps_invoice_editable_and_displays_warning(): void
    {
        $invoice = $this->invoice('issued');
        $payment = $this->payment($invoice, 'pending', '75.00');

        $this->get(route('invoices.edit', $invoice))->assertOk()->assertSee(
            'По инвойсу есть платёж, ожидающий подтверждения. После изменения инвойса сумма зарегистрированного платежа не изменится.'
        );

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, '125.00'))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', 'Инвойс успешно обновлён.');

        $payment->refresh();
        $this->assertSame('75.00', $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_invoice_with_pending_payment_can_be_increased_and_available_amount_is_updated(): void
    {
        $invoice = $this->invoice('issued');
        $payment = $this->payment($invoice, 'pending', '100.00');

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, '125.00'))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('125.00', $invoice->fresh()->total_amount);
        $this->assertSame('100.00', $payment->fresh()->amount);
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="25.00"', false)
            ->assertSee('25,00 ₼');
    }

    public function test_invoice_total_may_equal_pending_total_and_disables_new_payment_submission(): void
    {
        $invoice = $this->invoice('issued');
        $this->payment($invoice, 'pending', '100.00');

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, '100.00'))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('value="0.00"', false)
            ->assertSee('Остаток к оплате:')
            ->assertSee('0,00 ₼')
            ->assertSee('disabled', false);
    }

    public function test_invoice_cannot_be_reduced_below_pending_total_and_transaction_rolls_back(): void
    {
        $invoice = $this->invoice('issued');
        $line = $invoice->lines()->firstOrFail();
        $payment = $this->payment($invoice, 'pending', '100.00');

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->payload($invoice, '99.00'))
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors([
                'lines' => 'Сумма инвойса не может быть меньше суммы ожидающих платежей: 100,00 ₼.',
            ]);

        $this->assertSame('100.00', $invoice->fresh()->total_amount);
        $this->assertSame('100.00', $line->fresh()->amount);
        $this->assertSame('100.00', $payment->fresh()->amount);
        $this->assertSame('pending', $payment->status);
    }

    public function test_confirmed_payment_blocks_show_action_edit_url_and_concurrent_patch(): void
    {
        $invoice = $this->invoice('issued');
        $line = $invoice->lines()->firstOrFail();
        $confirmedPayment = $this->payment($invoice, 'confirmed', '10.00');
        DB::table('payment_allocations')->insert([
            'payment_id' => $confirmedPayment->id,
            'invoice_line_id' => $line->id,
            'amount' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $creditBalanceId = DB::table('credit_balances')->insertGetId([
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('credit_balance_entries')->insert([
            'credit_balance_id' => $creditBalanceId,
            'type' => 'applied',
            'amount' => '10.00',
            'payment_id' => $confirmedPayment->id,
            'invoice_id' => $invoice->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get(route('invoices.show', $invoice))->assertOk()->assertDontSee('Редактировать');
        $this->get(route('invoices.edit', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error', 'Инвойс уже получил оплату и больше не может быть изменён.');

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->payload($invoice, '999.00'))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error', 'Инвойс уже получил оплату и больше не может быть изменён.');

        $this->assertSame('100.00', $invoice->fresh()->total_amount);
        $this->assertSame('100.00', $line->fresh()->amount);
    }

    public function test_non_editable_statuses_are_blocked_with_expected_messages(): void
    {
        foreach (['partially_paid', 'paid'] as $status) {
            $invoice = $this->invoice($status);
            $this->get(route('invoices.edit', $invoice))
                ->assertRedirect(route('invoices.show', $invoice))
                ->assertSessionHas('error', 'Инвойс в текущем состоянии нельзя редактировать.');
        }

        $cancelled = $this->invoice('cancelled');
        $this->get(route('invoices.edit', $cancelled))
            ->assertRedirect(route('invoices.show', $cancelled))
            ->assertSessionHas('error', 'Отменённый инвойс нельзя редактировать.');
    }

    public function test_update_preserves_draft_or_issued_status_and_creates_no_financial_records(): void
    {
        foreach (['draft', 'issued'] as $status) {
            $invoice = $this->invoice($status);

            $this->put(route('invoices.update', $invoice), $this->payload($invoice, '125.00'))
                ->assertRedirect(route('invoices.show', $invoice));

            $invoice->refresh();
            $this->assertSame($status, $invoice->status);
            $this->assertSame('125.00', $invoice->total_amount);
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_cancelled_payments_do_not_block_editing(): void
    {
        $invoice = $this->invoice('issued');
        $this->payment($invoice, 'cancelled', '20.00');

        $this->get(route('invoices.edit', $invoice))->assertOk();
        $this->put(route('invoices.update', $invoice), $this->payload($invoice, '110.00'))
            ->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_api_uses_same_rule_and_ignores_protected_fields(): void
    {
        Sanctum::actingAs($this->authenticatedUser);
        $invoice = $this->invoice('issued');
        $originalTotal = $invoice->total_amount;
        $originalSeller = $invoice->seller_name;
        $originalPayer = $invoice->payer_name;

        $this->patchJson(route('api.invoices.update', $invoice), [
            'comment' => 'API update',
            'status' => 'cancelled',
            'total_amount' => '999.00',
            'seller_name' => 'Tampered seller',
            'payer_name' => 'Tampered payer',
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame($originalTotal, $invoice->total_amount);
        $this->assertSame($originalSeller, $invoice->seller_name);
        $this->assertSame($originalPayer, $invoice->payer_name);
        $this->assertSame('API update', $invoice->comment);

        $this->payment($invoice, 'confirmed', '10.00');
        $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Blocked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
        $this->assertSame('API update', $invoice->fresh()->comment);
    }

    private function invoice(string $status, string $dueDate = '2026-07-31'): Invoice
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
            'invoice_number' => 'EDIT-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => $dueDate,
            'total_amount' => '100.00',
            'status' => $status,
            'seller_name' => 'Seller',
            'payer_name' => 'Payer',
        ]);
        $invoice->lines()->create(['description' => 'Manual line', 'amount' => '100.00']);

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

    private function payload(Invoice $invoice, string $amount): array
    {
        $line = $invoice->lines()->firstOrFail();

        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'status' => 'cancelled',
            'total_amount' => '999.00',
            'lines' => [[
                'id' => $line->id,
                'description' => 'Updated line',
                'amount' => $amount,
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]],
        ];
    }
}
