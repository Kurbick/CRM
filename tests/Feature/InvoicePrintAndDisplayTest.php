<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FinancialTestCase as TestCase;

class InvoicePrintAndDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_route_renders_clean_a4_document_with_canonical_invoice_data(): void
    {
        $invoice = $this->invoice('issued', '119.00');
        $this->line($invoice, 'Веб-сайт и техническая поддержка', '100.00');
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);

        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'legal_name' => 'ZeroLine Legal Name',
            'bank_correspondent_account' => 'CORR-001',
            'bank_code' => 'BANK-001',
            'swift' => 'SWIFT001',
        ]);
        DB::table('companies')->where('id', $invoice->company_id)->update([
            'voen' => 'BUYER-001',
            'phone' => '+994 50 000 00 00',
        ]);
        $invoice->forceFill([
            'seller_name' => 'Snapshot Seller',
            'seller_voen' => 'SELLER-001',
            'seller_bank_name' => 'Snapshot Bank',
            'seller_iban' => 'AZ00SNAPSHOT',
            'seller_bank_voen' => 'BANK-V-001',
            'payer_name' => 'Buyer Company',
            'payer_voen' => 'BUYER-SNAPSHOT',
            'subtotal_amount' => '100.00',
            'vat_enabled' => true,
            'vat_rate' => '19.00',
            'vat_amount' => '19.00',
            'total_amount' => '119.00',
        ])->save();

        $response = $this->get(route('invoices.print', $invoice))->assertOk();

        $this->assertSame(6, substr_count($response->getContent(), 'class="invoice-empty-row"'));

        $response->assertSee('data-testid="invoice-print-document"', false)
            ->assertSee('data-logo-asset="images/zeroline-logo.png"', false)
            ->assertSee('src="'.asset('images/zeroline-logo.png').'"', false)
            ->assertSee('Snapshot Seller')
            ->assertSee('ZeroLine Legal Name')
            ->assertSee('CORR-001')
            ->assertSee('Buyer Company')
            ->assertSee('BUYER-SNAPSHOT')
            ->assertSee('+994 50 000 00 00')
            ->assertSee('По договору № '.$invoice->contract->contract_number.' — Веб-сайт и техническая поддержка (01.08.2026–31.08.2026)')
            ->assertSee('100,00 ₼')
            ->assertSee('100,00 ₼')
            ->assertSee('19,00 ₼')
            ->assertSee('119,00 ₼')
            ->assertSee('Итого')
            ->assertSee('НДС (19%)')
            ->assertSee('Всего к оплате')
            ->assertSee('<tfoot', false)
            ->assertSee('data-canonical-subtotal="100.00"', false)
            ->assertSee('class="invoice-empty-row"', false)
            ->assertSee('INVOICE')
            ->assertSee('Директор:')
            ->assertSee('М.П.')
            ->assertSee('СПАСИБО ЗА СОТРУДНИЧЕСТВО!')
            ->assertDontSee('crm-global-navigation')
            ->assertDontSee('print-toolbar', false)
            ->assertDontSee('party-grid', false)
            ->assertDontSee('zeroline-logo.svg', false)
            ->assertDontSee('Срок оплаты:')
            ->assertDontSee('Договор:')
            ->assertDontSee('Ожидает подтверждения:')
            ->assertDontSee('Остаток к оплате:')
            ->assertDontSee('Оплачено')
            ->assertDontSee('История платежей')
            ->assertDontSee('Credit Balance');
    }

    public function test_print_route_localizes_labels_and_line_periods_in_az(): void
    {
        $invoice = $this->invoice('issued', '200.00');
        $this->line($invoice, 'Veb-sayt xidməti', '100.00');
        $this->line($invoice, 'Texniki dəstək', '100.00');
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->limit(1)->update([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderByDesc('id')->limit(1)->update([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        $invoice->forceFill([
            'subtotal_amount' => '200.00',
            'vat_enabled' => false,
            'vat_amount' => '0.00',
            'total_amount' => '200.00',
        ])->save();

        $this->withSession(['locale' => 'az'])
            ->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee('Sifarişçi')
            ->assertSee('AÇIQLAMA')
            ->assertSee('MƏBLƏĞ')
            ->assertSee('(01.08.2026–31.08.2026)')
            ->assertSee('(01.09.2026–30.09.2026)')
            ->assertSee('Tarix: '.\Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d.m.Y'))
            ->assertSee('Hesab №: '.$invoice->invoice_number)
            ->assertSee('Ödəniləcək məbləğ')
            ->assertSee('BİZİMLƏ ƏMƏKDAŞLIQ ETDİYİNİZ ÜÇÜN TƏŞƏKKÜR EDİRİK!')
            ->assertDontSee('İnvoys tarixi:')
            ->assertDontSee('İnvoys nömrəsi:')
            ->assertDontSee('Cəmi')
            ->assertDontSee('ƏDV-siz məbləğ')
            ->assertDontSee('ƏDV (')
            ->assertDontSee('Ödəniş müddəti:');
    }

    public function test_az_invoice_show_uses_sifarisci_label(): void
    {
        $invoice = $this->invoice('issued', '100.00');
        $this->line($invoice, 'Veb-sayt xidməti', '100.00');

        $this->withSession(['locale' => 'az'])
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Sifarişçi')
            ->assertSee('aria-label="Çap və ixrac"', false)
            ->assertSee('Çap')
            ->assertSee('Word (.docx)')
            ->assertSee('Excel (.xlsx)')
            ->assertDontSee('Ödəyici');
    }

    public function test_print_route_uses_vat_snapshot_and_omits_vat_for_historical_vat_neutral_invoice(): void
    {
        $vatInvoice = $this->invoice('issued', '119.00');
        $this->line($vatInvoice, 'VAT service', '100.00');
        $vatInvoice->forceFill([
            'subtotal_amount' => '100.00',
            'vat_enabled' => true,
            'vat_rate' => '18.00',
            'vat_amount' => '18.00',
            'total_amount' => '118.00',
        ])->save();

        $this->get(route('invoices.print', $vatInvoice))
            ->assertOk()
            ->assertSee('НДС (18%)')
            ->assertSee('18,00 ₼')
            ->assertSee('118,00 ₼');

        $neutralInvoice = $this->invoice('issued', '100.00');
        $this->line($neutralInvoice, 'Legacy neutral service', '100.00');
        $neutralInvoice->forceFill([
            'subtotal_amount' => '100.00',
            'vat_enabled' => false,
            'vat_rate' => null,
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
        ])->save();

        $this->get(route('invoices.print', $neutralInvoice))
            ->assertOk()
            ->assertSee('100,00 ₼')
            ->assertSee('Всего к оплате')
            ->assertDontSee('Сумма без НДС')
            ->assertDontSee('НДС (');
    }

    public function test_az_vat_enabled_print_uses_word_totals_labels(): void
    {
        $invoice = $this->invoice('issued', '119.00');
        $this->line($invoice, 'Veb-sayt xidməti', '100.00');
        $invoice->forceFill([
            'subtotal_amount' => '100.00',
            'vat_enabled' => true,
            'vat_rate' => '19.00',
            'vat_amount' => '19.00',
            'total_amount' => '119.00',
        ])->save();

        $this->withSession(['locale' => 'az'])
            ->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee('Cəmi')
            ->assertSee('ƏDV (19%)')
            ->assertSee('Ödəniləcək məbləğ')
            ->assertSee('119,00 ₼');
    }

    public function test_print_route_outputs_every_multi_period_invoice_line_without_recalculating_amounts(): void
    {
        $invoice = $this->invoice('issued', '300.00');
        foreach ([
            ['description' => 'Период один', 'start' => '2026-08-01', 'end' => '2026-08-31'],
            ['description' => 'Период два', 'start' => '2026-09-01', 'end' => '2026-09-30'],
            ['description' => 'Период три', 'start' => '2026-10-01', 'end' => '2026-10-31'],
        ] as $line) {
            $lineId = $this->line($invoice, $line['description'], '100.00');
            DB::table('invoice_lines')->where('id', $lineId)->update([
                'period_start' => $line['start'],
                'period_end' => $line['end'],
            ]);
        }

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee('Период один')
            ->assertSee('Период два')
            ->assertSee('Период три')
            ->assertSee('(01.08.2026–31.08.2026)')
            ->assertSee('(01.09.2026–30.09.2026)')
            ->assertSee('(01.10.2026–31.10.2026)')
            ->assertSee('data-canonical-total="300.00"', false)
            ->assertSee('300,00 ₼');
    }

    public function test_invoice_html_contains_print_document_and_hides_crm_interface(): void
    {
        $invoice = $this->invoice('issued', '1350.00');
        $this->line($invoice, 'Большая работа', '1250.00');
        $this->line($invoice, 'Малая работа', '100.00');

        $response = $this->get(route('invoices.show', $invoice));

        $response->assertOk()
            ->assertSee('crm-global-navigation crm-print-hide', false)
            ->assertSee('href="'.route('invoices.print', $invoice).'"', false)
            ->assertDontSee('onclick="window.print()"', false)
            ->assertSee('invoice-page-header crm-print-hide', false)
            ->assertSee('invoice-document ', false)
            ->assertSee('Поставщик услуг')
            ->assertSee($invoice->invoice_number)
            ->assertSee('invoice-sidebar crm-print-hide', false)
            ->assertSee('data-testid="invoice-print-menu"', false)
            ->assertSee('aria-label="Печать и экспорт"', false)
            ->assertSee(route('invoices.export.word', $invoice), false)
            ->assertSee(route('invoices.export.excel', $invoice), false)
            ->assertSee('Word (.docx)')
            ->assertSee('Excel (.xlsx)')
            ->assertSee('invoice-payment-history crm-print-hide', false)
            ->assertSee('crm-print-hide pb-3 text-left pr-4 print:hidden">Оплачено', false)
            ->assertSee('crm-print-hide pb-3 text-left pr-4 print:hidden">Остаток', false)
            ->assertSee('crm-print-hide pb-3 print:hidden">Статус', false)
            ->assertSee('1 250,00 ₼')
            ->assertSee('100,00 ₼')
            ->assertSee('0,00 ₼');

        $content = $response->getContent();
        $this->assertStringNotContainsString('Дашборд', $content);
        $this->assertStringContainsString('@media print', $content);
        $this->assertStringContainsString('.crm-print-hide', $content);
        $this->assertStringContainsString('invoice-print-only hidden', $content);
    }

    public function test_invoice_document_preserves_identity_metadata_totals_and_lifecycle_action(): void
    {
        $invoice = $this->invoice('draft', '1350.00');
        $this->line($invoice, 'Работа по договору', '1350.00');
        $invoice->load(['company', 'contract']);
        $this->authenticatedUser->givePermissionTo([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
        ]);

        $response = $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('data-testid="invoice-entity-header"', false)
            ->assertSee('data-testid="invoice-workspace"', false)
            ->assertSee('data-testid="invoice-line-items"', false)
            ->assertDontSee('data-testid="invoice-financial-strip"', false)
            ->assertDontSee('data-testid="invoice-context"', false)
            ->assertSee('Позиции счета')
            ->assertSee('Оплачено')
            ->assertSee('Остаток')
            ->assertSee($invoice->invoice_number)
            ->assertSee($invoice->company->name)
            ->assertSee(route('companies.show', $invoice->company), false)
            ->assertSee($invoice->contract->contract_number)
            ->assertSee(route('contracts.show', $invoice->contract), false)
            ->assertSee('Работа по договору')
            ->assertSee('1 350,00 ₼')
            ->assertSee('Дата выставления:')
            ->assertSee('01/07/2026')
            ->assertSee('31/07/2026')
            ->assertSee('Срок оплаты:')
            ->assertSee('invoice-totals', false)
            ->assertSee('Выставить счёт');

    }

    public function test_stored_seller_snapshot_has_priority_over_current_configuration(): void
    {
        $invoice = $this->invoice('issued', '100.00');
        $this->line($invoice, 'Stored snapshot line', '100.00');
        $stored = $this->sellerValues('STORED SELLER');
        $configured = $this->sellerValues('CONFIGURED SELLER');
        $invoice->forceFill($stored)->save();
        $this->configureSeller($configured);

        $response = $this->get(route('invoices.show', $invoice))->assertOk();

        foreach ($stored as $value) {
            $response->assertSee($value);
        }
        foreach ($configured as $value) {
            $response->assertDontSee($value);
        }
    }

    public function test_legacy_null_seller_snapshot_uses_current_configuration_without_hardcoded_values(): void
    {
        $invoice = $this->invoice('issued', '100.00');
        $this->line($invoice, 'Legacy snapshot line', '100.00');
        $configured = $this->sellerValues('LEGACY CONFIG SELLER');
        $this->configureSeller($configured);

        $response = $this->get(route('invoices.show', $invoice))->assertOk();

        foreach ($configured as $value) {
            $response->assertSee($value);
        }
        foreach ([
            'Поставщик услуг',
            'VÖEN:',
            'Банк:',
            'IBAN:',
            'SWIFT:',
            'Код банка:',
        ] as $sellerLabel) {
            $response->assertSee($sellerLabel);
        }
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
            ->assertSee('Частично оплачен')
            ->assertDontSee('Частично оплачено')
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
        return Payment::withoutEvents(fn () => Payment::create([
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

    /** @return array<string, string> */
    private function sellerValues(string $prefix): array
    {
        $token = strtoupper(substr(hash('sha256', $prefix), 0, 8));

        return [
            'seller_name' => $prefix.' NAME',
            'seller_voen' => 'V'.$token,
            'seller_bank_name' => $prefix.' BANK',
            'seller_iban' => 'AZ00'.$token.'IBAN',
            'seller_bank_code' => 'C'.$token,
            'seller_bank_voen' => 'BV'.$token,
            'seller_swift' => 'S'.$token,
        ];
    }

    /** @param array<string, string> $values */
    private function configureSeller(array $values): void
    {
        config([
            'invoice.seller.name' => $values['seller_name'],
            'invoice.seller.voen' => $values['seller_voen'],
            'invoice.seller.bank_name' => $values['seller_bank_name'],
            'invoice.seller.iban' => $values['seller_iban'],
            'invoice.seller.bank_code' => $values['seller_bank_code'],
            'invoice.seller.bank_voen' => $values['seller_bank_voen'],
            'invoice.seller.swift' => $values['seller_swift'],
        ]);
    }
}
