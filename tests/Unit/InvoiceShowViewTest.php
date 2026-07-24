<?php

namespace Tests\Unit;

use Tests\TestCase;

class InvoiceShowViewTest extends TestCase
{
    public function test_invoice_view_uses_russian_labels_and_snapshot_fields(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString('>Плательщик</div>', $source);
        $this->assertStringContainsString('>Счёт</div>', $source);
        $this->assertStringContainsString('Дата выставления:', $source);
        $this->assertStringContainsString('Остаток к оплате:', $source);
        $this->assertStringContainsString('$invoice->payer_name', $source);
        $this->assertStringContainsString('$invoice->payer_voen', $source);
        $this->assertStringContainsString("trim((string) \$invoice->payer_voen) !== ''", $source);
        $this->assertStringNotContainsString("VÖEN: {{ \$invoice->payer_voen ?: 'Не указан' }}", $source);
        $this->assertStringContainsString('$invoice->contract_reference', $source);
        $this->assertStringNotContainsString('Связано с аккаунтом', $source);
        $this->assertStringNotContainsString('Ödəyici', $source);
        $this->assertStringNotContainsString('$invoice->company->name', $source);
    }

    public function test_invoice_view_formats_money_and_normalises_negative_zero(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString('$value == 0.0', $source);
        $this->assertStringContainsString("number_format(\$value, 2, ',', ' ')", $source);
        $this->assertSame(1, substr_count($source, '$formatMoney = static function'));
        $this->assertStringNotContainsString('$formatBreakdownMoney', $source);
        $this->assertStringContainsString('$formatMoney($line[\'amount\'])', $source);
        $this->assertStringContainsString('$formatMoney($paymentRow[\'amount\'])', $source);
        $this->assertStringContainsString('$formatMoney($invoice->remaining_amount)', $source);
    }

    public function test_invoice_view_describes_linked_lines_without_internal_ids(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString('$line[\'type_label\']', $source);
        $this->assertStringContainsString('$line[\'period_label\']', $source);
        $this->assertStringNotContainsString('{{ $line->subscription_id }}', $source);
        $this->assertStringNotContainsString('{{ $line->order_id }}', $source);
    }

    public function test_issue_action_is_single_and_placed_after_totals_outside_print(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $issueRoute = "route('invoices.issue', \$invoice)";

        $this->assertSame(1, substr_count($source, $issueRoute));
        $this->assertGreaterThan(strpos($source, 'Остаток к оплате:'), strpos($source, $issueRoute));
        $this->assertStringContainsString('class="crm-print-hide mt-4 flex justify-end print:hidden"', $source);
        $this->assertStringContainsString('class="w-64 max-w-full"', $source);
        $this->assertStringContainsString("route('invoices.edit', \$invoice)", $source);
        $this->assertStringContainsString("route('invoices.destroy', \$invoice)", $source);
        $this->assertStringContainsString("@method('DELETE')", $source);
    }

    public function test_issue_action_has_no_obsolete_browser_confirmation(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $issueRoutePosition = strpos($source, "route('invoices.issue', \$invoice)");
        $issueButtonPosition = strpos($source, 'Выставить счёт', $issueRoutePosition);
        $issueForm = substr($source, $issueRoutePosition, $issueButtonPosition - $issueRoutePosition);

        $this->assertStringNotContainsString('confirm(', $issueForm);
        $this->assertStringNotContainsString(
            'После этого свободное редактирование будет недоступно',
            $source
        );
        $this->assertStringContainsString('method="POST"', $issueForm);
        $this->assertStringContainsString('@csrf', $issueForm);
        $this->assertStringContainsString('Выставить счёт', $source);
    }

    public function test_payment_breakdown_columns_and_current_allocation_details_are_present(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString('>Оплачено</th>', $source);
        $this->assertStringContainsString('>Остаток</th>', $source);
        $this->assertStringContainsString('>Статус</th>', $source);
        $this->assertStringNotContainsString('>Состояние</th>', $source);
        $this->assertStringNotContainsString('>Тип / период</th>', $source);
        $this->assertStringContainsString('$line[\'payment_state_label\']', $source);
        $this->assertStringContainsString("\$line['type'] === 'subscription'", $source);
        $this->assertStringContainsString("{{ \$line['period_label'] }}", $source);
        $this->assertStringNotContainsString("\$line['type_label'] }}@if (\$line['period_label'])", $source);
        $this->assertStringContainsString('print:hidden', $source);
        $this->assertStringContainsString('overflow-x-auto', $source);
        $this->assertStringNotContainsString('min-w-[860px]', $source);
        $this->assertStringContainsString(":aria-label=\"allocationOpen ? 'Скрыть распределение' : 'Показать распределение'\"", $source);
        $this->assertStringNotContainsString("x-text=\"allocationOpen ? 'Скрыть распределение' : 'Показать распределение'\"", $source);
        $this->assertStringContainsString('x-show="!allocationOpen"', $source);
        $this->assertStringContainsString('x-show="allocationOpen" x-cloak aria-hidden="true"', $source);
        $this->assertStringContainsString(':aria-expanded="allocationOpen.toString()"', $source);
        $this->assertStringContainsString('aria-controls="payment-allocation-{{ $paymentRow[\'id\'] }}"', $source);
        $this->assertStringContainsString('id="payment-allocation-{{ $paymentRow[\'id\'] }}"', $source);
        $this->assertStringContainsString('Текущее распределение', $source);
        $this->assertStringNotContainsString('Отображается актуальное распределение после подтверждений и отмен платежей.', $source);
        $this->assertStringContainsString('Будет распределён после подтверждения.', $source);
        $this->assertStringNotContainsString('Текущее распределение отсутствует: платёж отменён.', $source);
        $this->assertStringNotContainsString("str_starts_with(\n", $source);
    }

    public function test_payment_cancellation_form_supports_enter_without_bypassing_validation(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString("route('payments.cancel', \$payment)", $source);
        $this->assertStringContainsString('name="cancel_reason"', $source);
        $this->assertStringContainsString('required minlength="3"', $source);
        $this->assertStringContainsString('type="submit" :disabled="cancelSubmitting"', $source);
        $this->assertStringContainsString('x-on:keydown.enter=', $source);
        $this->assertStringContainsString('if (!$event.shiftKey)', $source);
        $this->assertStringContainsString('$event.currentTarget.form.requestSubmit();', $source);
        $this->assertStringContainsString("value.trim()", $source);
        $this->assertStringContainsString('cancelSubmitting = true;', $source);
        $this->assertStringNotContainsString('$event.currentTarget.form.submit();', $source);
    }

    public function test_compact_payment_history_card_opens_an_accessible_drawer(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString("{{ \$paymentBreakdown['payments_count'] }}", $source);
        $this->assertStringContainsString('Последний платёж:', $source);
        $this->assertStringContainsString('Открыть историю', $source);
        $this->assertStringContainsString('paymentHistoryOpen:', $source);
        $this->assertStringContainsString('x-show="paymentHistoryOpen" x-cloak', $source);
        $this->assertStringContainsString('id="payment-history-drawer"', $source);
        $this->assertStringContainsString('payment-history-drawer crm-print-hide fixed inset-0 z-50 print:hidden', $source);
        $this->assertStringContainsString('payment-history-backdrop crm-print-hide absolute inset-0 bg-gray-900/40 print:hidden', $source);
        $this->assertStringContainsString('x-on:keydown.escape.window=', $source);
        $this->assertStringContainsString('aria-label="Закрыть историю платежей"', $source);
        $this->assertStringContainsString('overflow-y-auto', $source);
        $this->assertStringContainsString("@forelse (\$paymentBreakdown['paymentRows'] as \$paymentRow)", $source);
        $this->assertStringContainsString('$paymentBreakdown[\'pending_payments_count\']', $source);
        $this->assertStringNotContainsString('Показать ещё', $source);
        $this->assertStringNotContainsString('Скрыть историю', $source);
        $this->assertStringNotContainsString('hidden_by_default', $source);
        $this->assertStringNotContainsString('showAllHistory', $source);
        $this->assertStringContainsString("route('payments.confirm', \$payment)", $source);
        $this->assertStringContainsString("route('payments.cancel', \$payment)", $source);
        $this->assertStringContainsString('$event.currentTarget.form.requestSubmit();', $source);
        $this->assertStringContainsString('allocationOpen: false', $source);
        $this->assertStringContainsString("document.body.style.overflow = 'hidden'", $source);
        $this->assertStringContainsString("document.body.style.overflow = ''", $source);
        $this->assertStringContainsString('$refs.paymentHistoryClose.focus()', $source);
        $this->assertStringContainsString('$refs.paymentHistoryTrigger?.focus()', $source);
        $this->assertStringContainsString('class="invoice-payment-history crm-print-hide print:hidden"', $source);
        $this->assertStringNotContainsString('<div class="mt-3 rounded-lg border border-red-100 bg-red-50 p-3">', $source);
        $this->assertSame(2, substr_count($source, "\$paymentSource['credit_balance_applied_minor'] > 0"));
        $this->assertSame(2, substr_count($source, "Из баланса: {{ \$formatMoney(\$paymentSource['credit_balance_applied_amount']) }}"));
        $this->assertStringNotContainsString('Частично из баланса', $source);
        $this->assertStringContainsString("\$paymentSource['credit_balance_payment_ids']", $source);
        $this->assertStringNotContainsString('Оплата из Credit Balance', $source);
    }
}
