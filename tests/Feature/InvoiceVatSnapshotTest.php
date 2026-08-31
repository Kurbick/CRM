<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Invoices\CreateInvoice;
use App\Actions\Invoices\UpdateInvoice;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\InvoicePaymentBreakdownPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\FinancialTestCase as TestCase;

class InvoiceVatSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_and_contract_invoice_snapshot_their_issuer_vat_policy(): void
    {
        $zeroLine = Organization::query()->firstOrFail();
        $zeroLine->update([
            'name' => 'ZeroLine',
            'invoice_number_code' => 'ZL',
            'is_vat_payer' => true,
            'vat_rate' => '18.00',
        ]);
        $kurban = Organization::query()->create([
            'name' => 'Kurban',
            'invoice_number_code' => 'KR',
            'is_vat_payer' => false,
            'is_active' => false,
        ]);

        $company = Company::query()->create(['name' => 'VAT customer']);
        $manual = $this->createInvoice($company, null, '1000.00', 'MANUAL-VAT-001');

        $this->assertSame($zeroLine->id, $manual->issuer_organization_id);
        $this->assertTrue($manual->vat_enabled);
        $this->assertSame('18.00', $manual->vat_rate);
        $this->assertSame('1180.00', $manual->total_amount);

        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $zeroLine->id,
            'contract_number' => 'CTR-VAT-001',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $contractInvoice = $this->createInvoice($company, $contract, '1500.00', 'CONTRACT-VAT-001');

        $this->assertSame($zeroLine->id, $contractInvoice->issuer_organization_id);
        $this->assertTrue($contractInvoice->vat_enabled);
        $this->assertSame('18.00', $contractInvoice->vat_rate);
        $this->assertSame('1500.00', $contractInvoice->subtotal_amount);
        $this->assertSame('270.00', $contractInvoice->vat_amount);
        $this->assertSame('1770.00', $contractInvoice->total_amount);
    }

    public function test_manual_invoice_from_a_non_vat_issuer_keeps_the_net_total(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'invoice_number_code' => 'KR',
            'is_vat_payer' => false,
            'vat_rate' => '18.00',
        ]);
        $invoice = $this->createInvoice(
            Company::query()->create(['name' => 'Non VAT customer']),
            null,
            '1500.00',
            'NON-VAT-001',
        );

        $this->assertFalse($invoice->vat_enabled);
        $this->assertNull($invoice->vat_rate);
        $this->assertSame('0.00', $invoice->vat_amount);
        $this->assertSame('1500.00', $invoice->total_amount);
    }

    public function test_web_invoice_request_cannot_forge_vat_snapshot_fields(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'invoice_number_code' => 'ZL',
            'is_vat_payer' => true,
            'vat_rate' => '18.00',
        ]);
        $company = Company::query()->create(['name' => 'Forged VAT customer']);

        $this->from(route('invoices.create'))
            ->post(route('invoices.store'), [
                'company_id' => $company->id,
                'invoice_number' => 'FORGED-VAT-001',
                'issue_date' => '2026-08-28',
                'due_date' => '2026-09-27',
                'vat_enabled' => false,
                'vat_rate' => '99.00',
                'vat_amount' => '0.00',
                'subtotal_amount' => '1.00',
                'lines' => [[
                    'description' => 'Manual service',
                    'amount' => '1000.00',
                ]],
            ])
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('vat_rate');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_draft_recalculation_uses_stored_snapshot_after_organization_rate_changes(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'invoice_number_code' => 'ZL',
            'is_vat_payer' => true,
            'vat_rate' => '18.00',
        ]);
        $invoice = $this->createInvoice(Company::query()->create(['name' => 'Snapshot customer']), null, '1000.00');

        $organization->update(['vat_rate' => '19.00']);
        $line = $invoice->lines()->sole();
        $updated = app(UpdateInvoice::class)->execute($invoice, [], [[
            'id' => $line->id,
            'description' => $line->description,
            'amount' => '2000.00',
            'subscription_id' => null,
            'order_id' => null,
            'period_start' => null,
            'period_end' => null,
        ]]);

        $this->assertSame('18.00', $updated->vat_rate);
        $this->assertSame('2000.00', $updated->subtotal_amount);
        $this->assertSame('360.00', $updated->vat_amount);
        $this->assertSame('2360.00', $updated->total_amount);
    }

    public function test_gross_total_drives_payments_pending_credit_and_breakdown(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'invoice_number_code' => 'ZL',
            'is_vat_payer' => true,
            'vat_rate' => '18.00',
        ]);
        $company = Company::query()->create(['name' => 'Lifecycle VAT customer']);
        $invoice = $this->createInvoice($company, null, '1000.00');
        $invoice->forceFill(['status' => 'issued'])->saveQuietly();

        $pending = app(CreatePendingPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-28',
            'amount' => '1180.00',
            'payment_method' => 'transfer',
        ]);
        $this->assertSame('1180.00', $pending->amount);
        $this->assertSame(118000, app(\App\Services\InvoicePaymentAvailabilityService::class)->evaluate($invoice->fresh())['pending_minor']);

        $pending->delete();
        $firstPayment = app(CreateConfirmedPayment::class)->execute($invoice->fresh(), [
            'payment_date' => '2026-08-28',
            'amount' => '1000.00',
            'payment_method' => 'transfer',
        ]);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(180.0, $invoice->fresh()->remaining_amount);

        app(CreateConfirmedPayment::class)->execute($invoice->fresh(), [
            'payment_date' => '2026-08-28',
            'amount' => '180.00',
            'payment_method' => 'transfer',
        ]);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->remaining_amount);
        $this->assertNotNull($firstPayment->fresh());

        $creditInvoice = $this->createInvoice($company, null, '1000.00', 'CREDIT-VAT');
        $creditInvoice->forceFill(['status' => 'issued'])->saveQuietly();
        CreditBalance::query()->create([
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'amount' => '500.00',
        ]);
        $result = app(ApplyCreditToInvoice::class)->execute($creditInvoice);

        $this->assertTrue($result->applied);
        $this->assertSame(50000, $result->appliedAmountMinor);
        $this->assertSame(680.0, $creditInvoice->fresh()->remaining_amount);
        $this->assertSame('0.00', CreditBalance::query()->where('company_id', $company->id)->firstOrFail()->amount);

        $presented = app(InvoicePaymentBreakdownPresenter::class)->present($creditInvoice->fresh()->load([
            'lines', 'payments.allocations', 'payments.creditBalanceEntries',
        ]));
        $this->assertSame('1000.00', $presented['totals']['invoice_lines_total']);
    }

    public function test_legacy_invoice_created_without_vat_fields_remains_vat_neutral(): void
    {
        $organization = Organization::query()->firstOrFail();
        $organization->update(['is_vat_payer' => true, 'vat_rate' => '18.00']);
        $invoice = Invoice::query()->create([
            'company_id' => Company::query()->create(['name' => 'Legacy VAT customer'])->id,
            'invoice_number' => 'LEGACY-VAT-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '1000.00',
            'status' => 'draft',
        ]);

        $this->assertFalse($invoice->vat_enabled);
        $this->assertNull($invoice->vat_rate);
        $this->assertSame('0.00', $invoice->vat_amount);
        $this->assertSame('1000.00', $invoice->subtotal_amount);
        $this->assertSame('1000.00', $invoice->total_amount);
    }

    private function createInvoice(Company $company, ?Contract $contract, string $amount, ?string $number = null): Invoice
    {
        return app(CreateInvoice::class)->execute(
            $company,
            $contract,
            [
                'invoice_number' => $number,
                'issue_date' => '2026-08-28',
                'due_date' => '2026-09-27',
            ],
            [[
                'description' => 'VAT service',
                'amount' => $amount,
            ]],
        );
    }
}
