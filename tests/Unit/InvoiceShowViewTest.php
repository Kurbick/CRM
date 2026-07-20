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
        $this->assertStringContainsString('$formatMoney($line->amount)', $source);
        $this->assertStringContainsString('$formatMoney($payment->amount)', $source);
        $this->assertStringContainsString('$formatMoney($invoice->remaining_amount)', $source);
    }

    public function test_invoice_view_describes_linked_lines_without_internal_ids(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));

        $this->assertStringContainsString('Разовая услуга', $source);
        $this->assertStringContainsString('Подписка@if', $source);
        $this->assertStringContainsString("format('d/m/Y')", $source);
        $this->assertStringNotContainsString('{{ $line->subscription_id }}', $source);
        $this->assertStringNotContainsString('{{ $line->order_id }}', $source);
    }

    public function test_issue_action_is_single_and_placed_after_totals_outside_print(): void
    {
        $source = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $issueRoute = "route('invoices.issue', \$invoice)";

        $this->assertSame(1, substr_count($source, $issueRoute));
        $this->assertGreaterThan(strpos($source, 'Остаток к оплате:'), strpos($source, $issueRoute));
        $this->assertStringContainsString('class="mt-4 flex justify-end print:hidden"', $source);
        $this->assertStringContainsString('class="w-64 max-w-full"', $source);
        $this->assertStringContainsString("route('invoices.edit', \$invoice)", $source);
        $this->assertStringContainsString("route('invoices.destroy', \$invoice)", $source);
        $this->assertStringContainsString("@method('DELETE')", $source);
    }
}
