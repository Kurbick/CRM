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

    public function test_invoice_number_and_compact_chevron_link_to_show(): void
    {
        $source = file_get_contents(resource_path('views/invoices/index.blade.php'));

        $this->assertSame(2, substr_count($source, "route('invoices.show', \$invoice)"));
        $this->assertStringContainsString('aria-label="Открыть счёт {{ $invoice->invoice_number }}"', $source);
        $this->assertStringContainsString('title="Открыть счёт"', $source);
        $this->assertStringContainsString('class="h-[18px] w-[18px]"', $source);
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
        $this->assertStringNotContainsString('Частично из баланса', $source);
        $this->assertStringNotContainsString('creditBalanceEntries()', $source);
        $this->assertStringNotContainsString('allocations()', $source);
    }
}
