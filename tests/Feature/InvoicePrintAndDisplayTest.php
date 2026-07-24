<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePrintAndDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_html_contains_print_document_and_hides_crm_interface(): void
    {
        $invoice = $this->invoice('issued', '1350.00');
        $this->line($invoice, 'Большая работа', '1250.00');
        $this->line($invoice, 'Малая работа', '100.00');

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()
            ->assertSee('crm-global-navigation crm-print-hide', false)
            ->assertSee('invoice-page-header crm-print-hide', false)
            ->assertSee('invoice-document ', false)
            ->assertSee('Поставщик услуг')
            ->assertSee($invoice->invoice_number)
            ->assertSee('invoice-sidebar crm-print-hide', false)
            ->assertSee('invoice-payment-history crm-print-hide', false)
            ->assertSee('crm-print-hide pb-3 text-right pr-4 print:hidden">Оплачено', false)
            ->assertSee('crm-print-hide pb-3 text-right pr-4 print:hidden">Остаток', false)
            ->assertSee('crm-print-hide pb-3 print:hidden">Статус', false)
            ->assertSee('1 250,00 ₼')
            ->assertSee('100,00 ₼')
            ->assertSee('0,00 ₼');

        $content = $response->getContent();
        $this->assertStringContainsString('Дашборд', $content);
        $this->assertStringContainsString('@media print', $content);
        $this->assertStringContainsString('.crm-print-hide', $content);
        $this->assertStringContainsString('invoice-print-only hidden', $content);
    }

    public function test_payment_history_uses_precise_labels_and_accessible_responsive_drawer(): void
    {
        $invoice = $this->invoice('paid', '100.00');
        $line = $this->line($invoice, 'Работа с очень длинным описанием', '100.00');
        $payment = $this->payment($invoice, 'confirmed', '125.00');
        $this->allocation($payment, $line, '100.00');

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()
            ->assertDontSee('Не распределено / Credit Balance')
            ->assertSee('Переплата по платежу')
            ->assertSee('Сумма сверх стоимости счёта')
            ->assertSee('Применено к счёту')
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-labelledby="payment-history-title"', false)
            ->assertSee('aria-label="Закрыть историю платежей"', false)
            ->assertSee('type="button" x-ref="paymentHistoryTrigger"', false)
            ->assertSee('max-w-[480px]', false)
            ->assertSee('overflow-x-hidden', false);
    }

    public function test_draft_has_edit_issue_and_delete_actions_without_payment_form(): void
    {
        $invoice = $this->invoice('draft', '0.00');
        $this->line($invoice, 'Нулевая позиция', '0.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Редактировать')
            ->assertSee('Удалить')
            ->assertSee('Выставить счёт')
            ->assertSee('0,00 ₼')
            ->assertDontSee('Зарегистрировать платеж');
    }

    public function test_issued_has_payment_form_and_unpaid_values(): void
    {
        $invoice = $this->invoice('issued', '100.00');
        $this->line($invoice, 'Работа', '100.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Зарегистрировать платеж')
            ->assertSee('Не оплачено')
            ->assertSee('Остаток к оплате:')
            ->assertSee('Отменить счёт');
    }

    public function test_partially_paid_invoice_keeps_payment_form_and_distinguishes_line_states(): void
    {
        $invoice = $this->invoice('partially_paid', '200.00');
        $paidLine = $this->line($invoice, 'Закрытая работа', '100.00');
        $this->line($invoice, 'Открытая работа', '100.00');
        $payment = $this->payment($invoice, 'confirmed', '100.00');
        $this->allocation($payment, $paidLine, '100.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Зарегистрировать платеж')
            ->assertSee('Оплачено')
            ->assertSee('Не оплачено')
            ->assertSee('100,00 ₼');
    }

    public function test_paid_and_overpaid_invoices_hide_new_payment_form(): void
    {
        $paid = $this->invoice('paid', '100.00');
        $paidLine = $this->line($paid, 'Оплаченная работа', '100.00');
        $paidPayment = $this->payment($paid, 'confirmed', '100.00');
        $this->allocation($paidPayment, $paidLine, '100.00');

        $this->get(route('invoices.show', $paid))
            ->assertOk()
            ->assertDontSee('Зарегистрировать платеж')
            ->assertSee('0,00 ₼')
            ->assertSee('История платежей');

        $overpaid = $this->invoice('paid', '100.00');
        $overpaidLine = $this->line($overpaid, 'Переплаченная работа', '100.00');
        $overpayment = $this->payment($overpaid, 'confirmed', '125.00');
        $this->allocation($overpayment, $overpaidLine, '100.00');

        $this->get(route('invoices.show', $overpaid))
            ->assertOk()
            ->assertSee('Переплата:')
            ->assertSee('25,00 ₼')
            ->assertSee('Переплата по платежу')
            ->assertDontSee('Зарегистрировать платеж');
    }

    public function test_cancelled_invoice_has_no_payment_or_confirmation_actions(): void
    {
        $invoice = $this->invoice('cancelled', '100.00');
        $this->line($invoice, 'Отменённая работа', '100.00');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Отменён')
            ->assertSee('Печать')
            ->assertDontSee('Зарегистрировать платеж')
            ->assertDontSee('Подтвердить платёж');
    }

    public function test_cancel_reason_error_reopens_drawer_and_matching_payment_form(): void
    {
        $invoice = $this->invoice('issued', '100.00');
        $this->line($invoice, 'Работа', '100.00');
        $payment = $this->payment($invoice, 'pending', '10.00');
        $response = $this->followingRedirects()
            ->from(route('invoices.show', $invoice))
            ->patch(route('payments.cancel', $payment), [
                'cancel_payment_id' => (string) $payment->id,
                'cancel_reason' => '',
            ])
            ->assertOk();

        $response->assertOk()
            ->assertSee('paymentHistoryOpen: true', false)
            ->assertSee('cancelOpen: true', false)
            ->assertSee('Укажите причину отмены платежа.');
    }

    private function invoice(string $status, string $total): Invoice
    {
        $suffix = uniqid();
        $companyId = DB::table('companies')->insertGetId(['name' => 'Company '.$suffix]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
        ]);

        return Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => $total,
            'status' => $status,
        ]);
    }

    private function line(Invoice $invoice, string $description, string $amount): int
    {
        return DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function allocation(Payment $payment, int $lineId, string $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_line_id' => $lineId,
            'amount' => $amount,
        ]);
    }
}
