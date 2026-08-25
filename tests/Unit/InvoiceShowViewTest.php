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
        $this->assertStringContainsString('data-testid="invoice-entity-header"', $source);
        $this->assertStringContainsString('data-testid="invoice-workspace"', $source);
        $this->assertStringContainsString('>Позиции счета</h2>', $source);
        $this->assertStringNotContainsString("count(\$paymentBreakdown['lineRows'])", $source);
        $this->assertStringNotContainsString('data-testid="invoice-financial-strip"', $source);
        $this->assertStringNotContainsString('data-testid="invoice-context"', $source);
        $this->assertStringContainsString('$invoice->company->name', $source);
        $this->assertStringNotContainsString('Связано с аккаунтом', $source);
        $this->assertStringNotContainsString('Ödəyici', $source);
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

    public function test_issue_action_is_single_compact_right_aligned_action_after_totals(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $issueRoute = "route('invoices.issue', \$invoice)";

        $this->assertSame(1, substr_count($source, $issueRoute));
        $this->assertGreaterThan(strpos($source, 'invoice-totals'), strpos($source, $issueRoute));
        $this->assertStringContainsString('data-testid="invoice-issue-action-area"', $source);
        $this->assertStringContainsString(
            'class="crm-print-hide mt-5 flex justify-end border-t border-slate-200 pt-4 print:hidden"',
            $source
        );
        $this->assertStringContainsString(
            'method="POST" class="w-full sm:w-auto">',
            substr($source, strpos($source, $issueRoute))
        );
        $this->assertStringContainsString(
            'class="inline-flex w-full items-center justify-center rounded bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 sm:w-auto"',
            $source
        );
        $this->assertStringNotContainsString('class="w-full rounded bg-blue-600', $source);
        $this->assertStringContainsString("route('invoices.edit', \$invoice)", $source);
        $this->assertStringContainsString(
            "route('invoices.destroy', ['invoice' => \$invoice, ...\$companyContext['query']])",
            $source
        );
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
        $this->assertStringContainsString('value.trim()', $source);
        $this->assertStringContainsString('cancelSubmitting = true;', $source);
        $this->assertStringNotContainsString('$event.currentTarget.form.submit();', $source);
    }

    public function test_pending_payment_table_actions_are_compact_inline_and_cancel_form_uses_full_width_row(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $table = substr($source, strpos($source, '<table class="crm-table min-w-[620px] table-fixed">'));
        $table = substr($table, 0, strpos($table, '</table>'));

        $this->assertStringContainsString('<col class="w-[29%]">', $table);
        $sharedActionSizing = 'inline-flex !h-9 !min-h-0 !w-24 shrink-0 items-center justify-center whitespace-nowrap !rounded-md border !px-0 !py-0 !text-sm !font-medium';
        $this->assertSame(4, substr_count($source, $sharedActionSizing));
        $this->assertStringNotContainsString('min-w-', $sharedActionSizing);
        $this->assertStringContainsString('class="px-1 text-right"', $table);
        $this->assertStringContainsString('class="flex flex-wrap justify-end gap-1.5', $table);
        $this->assertStringContainsString('data-testid="invoice-payment-confirm-action"', $table);
        $this->assertStringContainsString('data-testid="invoice-payment-cancel-action"', $table);
        $this->assertStringContainsString('data-testid="invoice-payment-cancel-row-{{ $payment->id }}"', $table);
        $this->assertStringContainsString('<td colspan="5"', $table);
        $this->assertStringContainsString('class="w-full rounded-md border border-red-200', $table);

        $cancelFormPosition = strpos($table, '<form action="{{ route(\'payments.cancel\', $payment) }}"');
        $mainActionCellEnd = strpos($table, '</td>', strpos($table, 'data-testid="invoice-payment-actions-'));
        $cancelRowPosition = strpos($table, 'data-testid="invoice-payment-cancel-row-');

        $this->assertNotFalse($cancelFormPosition);
        $this->assertNotFalse($mainActionCellEnd);
        $this->assertNotFalse($cancelRowPosition);
        $this->assertGreaterThan($mainActionCellEnd, $cancelRowPosition);
        $this->assertGreaterThan($cancelRowPosition, $cancelFormPosition);
    }

    public function test_payment_history_keeps_actions_in_a_footer_and_preserves_cancellation_metadata(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $history = substr($source, strpos($source, 'id="payment-history-list"'));

        $this->assertStringContainsString('class="font-semibold font-mono text-gray-900"', $history);
        $this->assertStringContainsString('class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-400"', $history);
        $this->assertStringContainsString('class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3', $history);
        $this->assertStringContainsString('data-testid="invoice-history-confirm-action"', $history);
        $this->assertStringContainsString('data-testid="invoice-history-cancel-action"', $history);
        $this->assertStringContainsString('data-testid="invoice-history-cancellation-{{ $payment->id }}"', $history);
        $this->assertStringContainsString('class="mt-3 space-y-2 border-t border-red-100 pt-3"', $history);
        $this->assertStringContainsString('textarea id="cancel_reason_{{ $payment->id }}"', $history);
        $this->assertStringNotContainsString('text-gray-400 line-through', $history);
        $this->assertStringNotContainsString('rounded-lg border border-red-100 bg-red-50 p-3', $history);

        $amountPosition = strpos($history, 'class="font-semibold font-mono text-gray-900"');
        $actionsPosition = strpos($history, 'data-testid="invoice-history-actions-');
        $metadataPosition = strpos($history, 'data-testid="invoice-history-cancellation-');

        $this->assertGreaterThan($amountPosition, $actionsPosition);
        $this->assertGreaterThan($metadataPosition, $actionsPosition);
    }

    public function test_payment_details_drawer_remains_accessible_without_duplicate_history_summary(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString("{{ \$paymentBreakdown['payments_count'] }}", $source);
        $this->assertStringContainsString('Открыть историю платежей', $source);
        $this->assertStringNotContainsString('>Детали<', $source);
        $this->assertStringContainsString('paymentHistoryOpen:', $source);
        $this->assertStringContainsString('x-show="paymentHistoryOpen" x-cloak', $source);
        $this->assertStringContainsString('id="payment-history-drawer"', $source);
        $this->assertStringContainsString('payment-history-drawer crm-print-hide fixed inset-0 z-50 print:hidden', $source);
        $this->assertStringContainsString('payment-history-backdrop crm-print-hide absolute inset-0 bg-gray-900/40 print:hidden', $source);
        $this->assertStringContainsString('x-on:keydown.escape.window=', $source);
        $this->assertStringContainsString('aria-label="Закрыть историю платежей"', $source);
        $this->assertStringContainsString('overflow-y-auto', $source);
        $this->assertStringContainsString("@forelse (\$paymentBreakdown['paymentRows'] as \$paymentRow)", $source);
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
        $this->assertStringContainsString('trapPaymentHistoryFocus(event)', $source);
        $this->assertStringContainsString('x-ref="paymentHistoryDrawer"', $source);
        $this->assertStringContainsString('x-on:keydown.tab="trapPaymentHistoryFocus($event)"', $source);
        $this->assertStringContainsString('document.activeElement === first', $source);
        $this->assertStringContainsString('document.activeElement === last', $source);
        $this->assertStringContainsString('class="invoice-payment-history crm-print-hide print:hidden"', $source);
        $this->assertStringNotContainsString('<div class="mt-3 rounded-lg border border-red-100 bg-red-50 p-3">', $source);
        $this->assertSame(1, substr_count($source, "\$paymentSource['credit_balance_applied_minor'] > 0"));
        $this->assertSame(1, substr_count($source, "Из баланса: {{ \$formatMoney(\$paymentSource['credit_balance_applied_amount']) }}"));
        $this->assertStringContainsString('class="w-64 text-right text-xs font-medium text-blue-700"', $source);
        $this->assertStringNotContainsString('Частично из баланса', $source);
        $this->assertStringContainsString("\$paymentSource['credit_balance_payment_ids']", $source);
        $this->assertStringNotContainsString('Оплата из Credit Balance', $source);
    }
}
