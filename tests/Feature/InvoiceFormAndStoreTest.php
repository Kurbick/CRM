<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceFormAndStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_is_a_compact_draft_form(): void
    {
        [$companyId] = $this->companyAndContract();

        $response = $this->withSession([
            '_old_input' => [
                'company_id' => (string) $companyId,
                'contract_id' => '123',
                'invoice_number' => 'INV-OLD',
                'issue_date' => '2026-07-06',
                'due_date' => '2026-08-05',
                'comment' => 'Old comment',
                'lines' => [[
                    'description' => 'Old line',
                    'amount' => '15.00',
                ]],
            ],
        ])->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('Новый счёт')
            ->assertSee('Сохранить черновик')
            ->assertSee('Оплатить до')
            ->assertSee('Добавить ручную позицию')
            ->assertSee('name="company_id"', false)
            ->assertSee("selectedCompanyId: '" . $companyId . "'", false)
            ->assertSee("invoiceNumber: 'INV-OLD'", false)
            ->assertSee("dueDate: '2026-08-05'", false)
            ->assertSee("comment: 'Old comment'", false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="payer_name"', false)
            ->assertDontSee('name="payer_voen"', false)
            ->assertDontSee('name="contract_reference"', false)
            ->assertDontSee('Позиции ещё не выбраны');
    }

    public function test_create_form_declares_strict_company_contract_visibility_cascade(): void
    {
        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('data-step="contract"', false)
            ->assertSee('x-show="selectedCompanyId"', false)
            ->assertSee('data-step="invoice-details"', false)
            ->assertSee('data-step="invoice-lines"', false)
            ->assertSee('x-show="selectedContractId"', false)
            ->assertSee('contractLabel(c) { return `№ ${c.contract_number}` }', false)
            ->assertSee('`с ${this.formatDate(c.start_date)}, бессрочный`', false)
            ->assertSee(':disabled="!selectedCompanyId || !selectedContractId || !lines.length"', false);
    }

    public function test_create_form_declares_complete_user_initiated_resets(): void
    {
        $response = $this->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('clearCompany() { this.resetAll() }', false)
            ->assertSee('this.selectedCompanyId = \'\';', false)
            ->assertSee('this.selectedContractId = \'\';', false)
            ->assertSee('this.contracts = [];', false)
            ->assertSee('this.availableItems = [];', false)
            ->assertSee('this.lines = [];', false)
            ->assertSee('this.invoiceNumber = \'\';', false)
            ->assertSee('this.issueDate = \'\';', false)
            ->assertSee('this.dueDate = \'\';', false)
            ->assertSee('this.comment = \'\';', false)
            ->assertSee('this.dueDateIsManual = false;', false)
            ->assertSee('this.initialiseNewInvoice();', false)
            ->assertSee('При смене компании все введённые данные счёта будут очищены. Продолжить?', false);
    }

    public function test_store_forces_draft_and_server_snapshots_and_ignores_invoice_periods(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('Main', 'AZ123', 'C-001');

        $response = $this->post(route('invoices.store'), array_merge(
            $this->basePayload($companyId, $contractId),
            [
                'status' => 'issued',
                'payer_name' => 'Forged payer',
                'payer_voen' => 'FORGED',
                'contract_reference' => 'FORGED-CONTRACT',
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        ));

        $invoice = Invoice::query()->sole();
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('Main', $invoice->payer_name);
        $this->assertSame('AZ123', $invoice->payer_voen);
        $this->assertSame('C-001', $invoice->contract_reference);
        $this->assertNull($invoice->period_start);
        $this->assertNull($invoice->period_end);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'subscription_id' => null,
            'order_id' => null,
            'period_start' => null,
            'period_end' => null,
        ]);
    }

    public function test_contract_must_belong_to_selected_company(): void
    {
        [$companyId] = $this->companyAndContract('First', null, 'FIRST');
        [, $otherContractId] = $this->companyAndContract('Second', null, 'SECOND');

        $this->from(route('invoices.create'))
            ->post(route('invoices.store'), $this->basePayload($companyId, $otherContractId))
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('contract_id');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_store_requires_at_least_one_line(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $payload = $this->basePayload($companyId, $contractId);
        unset($payload['lines']);

        $this->post(route('invoices.store'), $payload)
            ->assertSessionHasErrors('lines');
    }

    public function test_edit_does_not_offer_company_contract_or_status_changes_and_preserves_snapshots(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('Original', 'V-1', 'CONTRACT-1');
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-EDIT-FORM',
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-19',
            'total_amount' => 10,
            'status' => 'draft',
            'payer_name' => 'Historic payer',
            'payer_voen' => 'Historic VOEN',
            'contract_reference' => 'Historic contract',
        ]);
        $line = $invoice->lines()->create(['description' => 'Manual', 'amount' => 10]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Компания:')
            ->assertSee('Договор:')
            ->assertDontSee('name="company_id"', false)
            ->assertDontSee('name="contract_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="payer_name"', false);

        $this->put(route('invoices.update', $invoice), [
            'invoice_number' => 'INV-EDIT-FORM',
            'issue_date' => '2026-07-21',
            'due_date' => '2026-08-20',
            'status' => 'paid',
            'company_id' => 999999,
            'contract_id' => 999999,
            'payer_name' => 'Forged',
            'payer_voen' => 'Forged',
            'contract_reference' => 'Forged',
            'period_start' => '2020-01-01',
            'period_end' => '2020-12-31',
            'lines' => [[
                'id' => $line->id,
                'description' => 'Updated manual',
                'amount' => 12,
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]],
        ])->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame($companyId, $invoice->company_id);
        $this->assertSame($contractId, $invoice->contract_id);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('Historic payer', $invoice->payer_name);
        $this->assertSame('Historic VOEN', $invoice->payer_voen);
        $this->assertSame('Historic contract', $invoice->contract_reference);
    }

    private function companyAndContract(
        string $company = 'Company',
        ?string $voen = 'VOEN',
        string $contract = 'CONTRACT'
    ): array {
        $companyId = DB::table('companies')->insertGetId([
            'name' => $company,
            'voen' => $voen,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => $contract,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        return [$companyId, $contractId];
    }

    private function basePayload(int $companyId, int $contractId): array
    {
        return [
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-' . $companyId . '-' . $contractId,
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-19',
            'lines' => [[
                'description' => 'Manual line',
                'amount' => 25,
            ]],
        ];
    }
}
