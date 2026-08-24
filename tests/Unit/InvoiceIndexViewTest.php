<?php

namespace Tests\Unit;

use Tests\TestCase;

class InvoiceIndexViewTest extends TestCase
{
    public function test_index_uses_company_relation_with_snapshot_fallback(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('Компания', $source);
        $this->assertStringNotContainsString('Плательщик / Компания', $source);
        $this->assertStringContainsString("'company' => \$invoice->company", $source);
        $this->assertStringContainsString("'return_url' => request()->fullUrl()", $source);
        $this->assertStringContainsString('@if ($invoice->company)', $source);
        $this->assertStringContainsString('{{ $invoice->payer_name }}', $source);
        $this->assertStringNotContainsString('Компания:', $source);
    }

    public function test_invoice_number_and_clickable_row_link_to_show(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString(
            '<x-tables.clickable-row :url="route(\'invoices.show\', $invoice)"',
            $source
        );
        $this->assertStringNotContainsString('aria-label="Открыть счёт', $source);
        $this->assertStringNotContainsString('h-[18px] w-[18px]', $source);
        $this->assertStringNotContainsString('Открыть →', $source);
    }

    public function test_cancelled_invoice_has_neutral_balance_state_and_money_is_localised(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString("\$invoice->status === 'cancelled'", $source);
        $this->assertStringContainsString('Счёт отменён', $source);
        $this->assertStringContainsString('$value == 0.0', $source);
        $this->assertStringContainsString("number_format(\$value, 2, ',', ' ')", $source);
    }

    public function test_index_renders_compact_precalculated_payment_source_marker(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('$paymentSource = $invoicePaymentSources->get($invoice->id)', $source);
        $this->assertStringContainsString("\$paymentSource['credit_balance_applied_minor'] > 0", $source);
        $this->assertStringContainsString("Из баланса: {{ \$formatMoney(\$paymentSource['credit_balance_applied_amount']) }}", $source);
        $this->assertStringContainsString('class="mt-0.5 text-[11px] font-medium text-blue-700"', $source);
        $this->assertStringNotContainsString('Частично из баланса', $source);
        $this->assertStringNotContainsString('creditBalanceEntries()', $source);
        $this->assertStringNotContainsString('allocations()', $source);
    }

    public function test_status_filter_is_multi_select_with_unpaid_compatibility_controls(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString("{ value: 'issued', label: 'Выставлен' }", $source);
        $this->assertStringContainsString('selectedStatuses: @js($activeStatuses)', $source);
        $this->assertStringContainsString('name="statuses[]"', $source);
        $this->assertStringContainsString("'Статусы: ' + this.selectedStatuses.length", $source);
        $this->assertStringContainsString('name="unpaid"', $source);
        $this->assertStringContainsString('removeIncompatibleStatuses()', $source);
        $this->assertStringContainsString(':disabled="unpaid && !isCompatible(status.value)"', $source);
        $this->assertStringContainsString("{ value: 'partially_paid', label: 'Частично оплачен' }", $source);
        $this->assertStringContainsString("{ value: 'cancelled', label: 'Отменён' }", $source);
    }

    public function test_contract_filter_uses_the_company_style_custom_dropdown(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('name="contract_id" x-model="selectedId"', $source);
        $this->assertStringContainsString('selectedLabel()', $source);
        $this->assertStringContainsString("'Все договоры'", $source);
        $this->assertStringContainsString('selectContract(contract)', $source);
        $this->assertStringContainsString('rounded-lg border border-gray-200 bg-white shadow-lg', $source);
        $this->assertStringNotContainsString('<select name="contract_id"', $source);
    }

    public function test_company_and_contract_closed_values_reserve_controls_and_truncate_labels(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('class="flex w-full min-w-0 items-center overflow-hidden rounded-lg border border-gray-200 bg-white transition focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500"', $source);
        $this->assertStringContainsString('class="min-w-0 flex-1 truncate overflow-hidden text-ellipsis whitespace-nowrap !border-0 !bg-transparent px-3 py-2 pr-0 text-sm outline-none transition focus:!border-0 focus:ring-0"', $source);
        $this->assertStringContainsString(':title="selectedId ? query : \'\'"', $source);
        $this->assertStringContainsString('class="flex-none px-2 text-gray-400 hover:text-red-500 transition"', $source);
        $this->assertStringContainsString('class="flex-none px-3 text-gray-400 hover:text-gray-600 transition"', $source);
        $this->assertStringContainsString('class="flex w-full min-w-0 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-left text-sm text-gray-700 hover:border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"', $source);
        $this->assertStringContainsString('class="min-w-0 flex-1 truncate overflow-hidden text-ellipsis whitespace-nowrap"', $source);
        $this->assertStringContainsString(':title="selectedId ? selectedLabel : \'\'"', $source);
        $this->assertStringContainsString('class="flex-none text-gray-400"', $source);
        $this->assertStringNotContainsString('class="absolute inset-y-0 right-8 flex items-center px-2 text-gray-400 hover:text-red-500 transition"', $source);
        $this->assertStringNotContainsString('class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"', $source);
    }

    public function test_company_and_contract_selection_submit_the_existing_filter_form(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));
        $submitExpression = "this.\$nextTick(() => this.\$root.closest('form').requestSubmit());";

        $this->assertSame(4, substr_count($source, $submitExpression));
        $this->assertStringContainsString("selectContract(contract) {\n                    this.selectedId = String(contract.id);\n                    this.open = false;\n                    this.\$nextTick(() => this.\$root.closest('form').requestSubmit());\n                }", $source);
        $this->assertStringContainsString("clearContract() {\n                    this.selectedId = '';\n                    this.open = false;\n                    this.\$nextTick(() => this.\$root.closest('form').requestSubmit());\n                }", $source);
        $this->assertStringContainsString('name="search"', $source);
        $this->assertStringContainsString('name="statuses[]"', $source);
        $this->assertStringContainsString('name="overdue"', $source);
        $this->assertStringContainsString('name="unpaid"', $source);
    }

    public function test_only_condition_checkboxes_submit_the_filter_form_immediately(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertStringContainsString('id="overdue" value="1"', $source);
        $this->assertStringContainsString('x-on:change="$el.closest(\'form\').requestSubmit()"', $source);
        $this->assertStringContainsString('id="unpaid" value="1" x-model="unpaid"', $source);
        $this->assertStringContainsString('x-on:change="removeIncompatibleStatuses(); $nextTick(() => $el.closest(\'form\').requestSubmit())"', $source);
        $this->assertStringNotContainsString('x-on:change="this.form.submit()"', $source);
    }
}
