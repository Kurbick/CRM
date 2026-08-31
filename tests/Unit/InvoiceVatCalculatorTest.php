<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Organization;
use App\Services\InvoiceVatCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceVatCalculatorTest extends TestCase
{
    public function test_snapshot_uses_integer_minor_units_and_gross_total(): void
    {
        $organization = new Organization([
            'is_vat_payer' => true,
            'vat_rate' => '18.00',
        ]);

        $snapshot = app(InvoiceVatCalculator::class)->snapshotForOrganization($organization, 150000);

        $this->assertSame([
            'vat_enabled' => true,
            'vat_rate' => '18.00',
            'subtotal_amount' => '1500.00',
            'vat_amount' => '270.00',
            'total_amount' => '1770.00',
        ], $snapshot);
    }

    public function test_rounding_is_deterministic_for_fractional_cents_and_multiple_lines(): void
    {
        $organization = new Organization([
            'is_vat_payer' => true,
            'vat_rate' => '50.00',
        ]);

        $snapshot = app(InvoiceVatCalculator::class)->snapshotForOrganization($organization, 5);

        $this->assertSame('0.05', $snapshot['subtotal_amount']);
        $this->assertSame('0.03', $snapshot['vat_amount']);
        $this->assertSame('0.08', $snapshot['total_amount']);
    }

    public function test_recalculation_keeps_the_invoice_snapshot_after_organization_changes(): void
    {
        $organization = new Organization([
            'is_vat_payer' => false,
            'vat_rate' => '19.00',
        ]);
        $invoice = new Invoice([
            'vat_enabled' => true,
            'vat_rate' => '18.00',
        ]);

        $snapshot = app(InvoiceVatCalculator::class)->recalculateFromInvoice($invoice, 200000);

        $this->assertSame('18.00', $snapshot['vat_rate']);
        $this->assertSame('360.00', $snapshot['vat_amount']);
        $this->assertSame('2360.00', $snapshot['total_amount']);
    }

    public function test_enabled_organization_without_a_valid_rate_returns_a_localized_validation_error(): void
    {
        $organization = new Organization([
            'is_vat_payer' => true,
            'vat_rate' => null,
        ]);

        try {
            app(InvoiceVatCalculator::class)->snapshotForOrganization($organization, 10000);
            $this->fail('Expected a VAT rate validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Для организации не настроена ставка НДС.',
                $exception->errors()['organization'][0]
            );
        }
    }
}
