<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FinancialTestCase as TestCase;

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
                    'order_id' => '456',
                ]],
            ],
        ])->get(route('invoices.create'));

        $response->assertOk()
            ->assertSee('Новый инвойс')
            ->assertSee('data-testid="invoice-create-form-workspace"', false)
            ->assertSee('Позиции счета')
            ->assertSee('Итого')
            ->assertSee('Сохранить черновик')
            ->assertSee('Оплатить до')
            ->assertDontSee('Добавить ручную позицию')
            ->assertDontSee('addManualLine()', false)
            ->assertDontSee(':name="`lines[${index}][amount]`"', false)
            ->assertSee('name="company_id"', false)
            ->assertSee("selectedCompanyId: '".$companyId."'", false)
            ->assertSee("invoiceNumber: 'INV-OLD'", false)
            ->assertSee("dueDate: '2026-08-05'", false)
            ->assertSee("comment: 'Old comment'", false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="payer_name"', false)
            ->assertDontSee('name="payer_voen"', false)
            ->assertDontSee('name="contract_reference"', false)
            ->assertDontSee('grid grid-cols-1 gap-6 lg:grid-cols-3', false)
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

    public function test_web_store_creates_a_draft_from_a_contract_subject_with_server_snapshots(): void
    {
        $sellerSnapshot = $this->configureSeller('WEB SELLER SNAPSHOT');
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
        $this->assertSellerSnapshot($invoice, $sellerSnapshot);
        $line = $invoice->lines()->sole();
        $this->assertNotNull($line->order_id);
        $this->assertNull($line->subscription_id);
        $this->assertNull($line->period_start);
        $this->assertNull($line->period_end);
        $this->assertSame('25.00', $line->amount);
        $this->assertSame('25.00', $invoice->total_amount);
    }

    public function test_web_store_uses_the_order_amount_instead_of_a_crafted_amount(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $payload = $this->basePayload($companyId, $contractId, '1200.00');
        $payload['lines'][0]['amount'] = '1.00';

        $this->post(route('invoices.store'), $payload)->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame('1200.00', $invoice->lines()->sole()->amount);
        $this->assertSame('1200.00', $invoice->total_amount);
    }

    public function test_web_store_creates_a_subject_backed_line_without_an_amount_field(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $payload = $this->basePayload($companyId, $contractId, '1200.00');
        unset($payload['lines'][0]['amount']);

        $this->post(route('invoices.store'), $payload)->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame('1200.00', $invoice->lines()->sole()->amount);
        $this->assertSame('1200.00', $invoice->total_amount);
    }

    public function test_web_store_uses_the_subscription_amount_instead_of_a_crafted_amount(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $subscriptionId = $this->subscription($contractId, '1200.00');
        $payload = $this->basePayload($companyId, $contractId);
        $payload['lines'] = [[
            'description' => 'Subscription line',
            'amount' => '1.00',
            'subscription_id' => $subscriptionId,
        ]];

        $this->post(route('invoices.store'), $payload)->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame('1200.00', $invoice->lines()->sole()->amount);
        $this->assertSame('1200.00', $invoice->total_amount);
    }

    public function test_web_store_rejects_a_crafted_manual_line_without_creating_an_invoice(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $payload = $this->basePayload($companyId, $contractId);
        $payload['lines'] = [[
            'description' => 'Forged manual line',
            'amount' => '25.00',
        ]];

        $this->from(route('invoices.create'))
            ->post(route('invoices.store'), $payload)
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('lines.0');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    public function test_web_update_rejects_a_crafted_new_manual_line_and_preserves_a_legacy_line(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-LEGACY-MANUAL',
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-19',
            'total_amount' => '25.00',
            'status' => 'draft',
        ]);
        $legacyLine = $invoice->lines()->create([
            'description' => 'Legacy manual line',
            'amount' => '25.00',
        ]);

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), [
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => '2026-07-20',
                'due_date' => '2026-08-19',
                'lines' => [
                    [
                        'id' => $legacyLine->id,
                        'description' => 'Legacy manual line',
                        'amount' => '25.00',
                        'subscription_id' => null,
                        'order_id' => null,
                        'period_start' => null,
                        'period_end' => null,
                    ],
                    [
                        'description' => 'Forged new manual line',
                        'amount' => '10.00',
                        'subscription_id' => null,
                        'order_id' => null,
                        'period_start' => null,
                        'period_end' => null,
                    ],
                ],
            ])
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('lines.1.id');

        $this->assertDatabaseCount('invoice_lines', 1);
        $this->assertSame('Legacy manual line', $legacyLine->fresh()->description);
        $this->assertSame('25.00', $legacyLine->fresh()->amount);
    }

    public function test_web_update_preserves_a_subject_line_amount_against_a_crafted_request(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $orderId = $this->order($contractId, '1200.00');
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-SUBJECT-SNAPSHOT',
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-03',
            'total_amount' => '1200.00',
            'status' => 'draft',
        ]);
        $line = $invoice->lines()->create([
            'order_id' => $orderId,
            'description' => 'Subject line',
            'amount' => '1200.00',
        ]);

        $this->put(route('invoices.update', $invoice), $this->subjectLineUpdatePayload($invoice, $line, '1.00'))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('1200.00', $line->fresh()->amount);
        $this->assertSame('1200.00', $invoice->fresh()->total_amount);
    }

    public function test_web_update_keeps_the_historical_subject_amount_after_the_subject_price_changes(): void
    {
        [$companyId, $contractId] = $this->companyAndContract();
        $orderId = $this->order($contractId, '1200.00');
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-HISTORICAL-SNAPSHOT',
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-03',
            'total_amount' => '1200.00',
            'status' => 'draft',
        ]);
        $line = $invoice->lines()->create([
            'order_id' => $orderId,
            'description' => 'Historical subject line',
            'amount' => '1200.00',
        ]);
        DB::table('orders')->where('id', $orderId)->update(['price' => '1500.00']);

        $payload = $this->subjectLineUpdatePayload($invoice, $line);
        unset($payload['lines'][0]['amount']);
        $this->put(route('invoices.update', $invoice), $payload)
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('1200.00', $line->fresh()->amount);
        $this->assertSame('1200.00', $invoice->fresh()->total_amount);
    }

    public function test_web_store_rejects_forged_seller_fields_without_mutation(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('Forged seller payer');
        $forged = [
            'seller_name' => 'WEB FORGED SELLER NAME',
            'seller_voen' => 'WEB-FORGED-VOEN',
            'seller_bank_name' => 'WEB FORGED BANK',
            'seller_iban' => 'WEB-FORGED-IBAN',
            'seller_bank_code' => 'WEB-FORGED-CODE',
            'seller_bank_voen' => 'WEB-FORGED-BANK-VOEN',
            'seller_swift' => 'WEB-FORGED-SWIFT',
        ];

        $this->post(
            route('invoices.store'),
            $this->basePayload($companyId, $contractId) + $forged
        )->assertSessionHasErrors(array_keys($forged));

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_api_and_web_creation_use_the_same_seller_snapshot(): void
    {
        $sellerSnapshot = $this->configureSeller('PARITY SELLER');
        [$webCompanyId, $webContractId] = $this->companyAndContract(
            'Web payer',
            contract: 'WEB-PARITY-CONTRACT'
        );
        [$apiCompanyId, $apiContractId] = $this->companyAndContract(
            'API payer',
            contract: 'API-PARITY-CONTRACT'
        );

        $this->post(
            route('invoices.store'),
            $this->basePayload($webCompanyId, $webContractId)
        )->assertRedirect();

        $apiPayload = $this->basePayload($apiCompanyId, $apiContractId);
        $apiPayload['total_amount'] = '999.99';
        $this->postJson(
            route('api.companies.invoices.store', ['company' => $apiCompanyId]),
            $apiPayload
        )->assertCreated();

        $invoices = Invoice::query()->orderBy('id')->get();
        $this->assertCount(2, $invoices);
        $this->assertSellerSnapshot($invoices[0], $sellerSnapshot);
        $this->assertSellerSnapshot($invoices[1], $sellerSnapshot);
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
            ->assertSee('Редактирование инвойса')
            ->assertSee('data-testid="invoice-edit-form-workspace"', false)
            ->assertSee('Компания:')
            ->assertSee('Договор:')
            ->assertSee('Позиции счета')
            ->assertSee('Итого')
            ->assertDontSee('grid grid-cols-1 gap-6 lg:grid-cols-3', false)
            ->assertDontSee('name="company_id"', false)
            ->assertDontSee('name="contract_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="payer_name"', false)
            ->assertDontSee('Добавить ручную позицию')
            ->assertDontSee('addLine()', false)
            ->assertSee('x-if="line.order_id || line.subscription_id"', false)
            ->assertSee('x-if="!line.order_id && !line.subscription_id"', false);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Manual')
            ->assertSee('Ручная позиция');

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
        $this->assertSame('Updated manual', $line->fresh()->description);
        $this->assertSame('12.00', $line->fresh()->amount);
        $this->assertDatabaseCount('invoice_lines', 1);
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

    private function basePayload(int $companyId, int $contractId, string $orderAmount = '25.00'): array
    {
        $orderId = $this->order($contractId, $orderAmount);

        return [
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$companyId.'-'.$contractId,
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-19',
            'lines' => [[
                'description' => 'Contract line',
                'amount' => $orderAmount,
                'order_id' => $orderId,
            ]],
        ];
    }

    private function order(int $contractId, string $amount = '25.00'): int
    {
        return DB::table('orders')->insertGetId([
            'contract_id' => $contractId,
            'title' => 'One-time service',
            'order_date' => '2026-07-01',
            'price' => $amount,
            'payment_terms' => 14,
        ]);
    }

    private function subscription(int $contractId, string $amount): int
    {
        return DB::table('subscriptions')->insertGetId([
            'contract_id' => $contractId,
            'title' => 'Monthly service',
            'start_date' => '2026-01-01',
            'next_billing_date' => '2026-07-01',
            'billing_period' => 'monthly',
            'amount' => $amount,
            'payment_terms' => 14,
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function subjectLineUpdatePayload(Invoice $invoice, InvoiceLine $line, ?string $amount = null): array
    {
        $lineData = [
            'id' => $line->id,
            'description' => $line->description,
            'subscription_id' => $line->subscription_id,
            'order_id' => $line->order_id,
            'period_start' => $line->period_start,
            'period_end' => $line->period_end,
        ];

        if ($amount !== null) {
            $lineData['amount'] = $amount;
        }

        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-03',
            'lines' => [$lineData],
        ];
    }

    /** @return array<string, string> */
    private function configureSeller(string $prefix): array
    {
        $token = strtoupper(substr(hash('sha256', $prefix), 0, 8));
        $snapshot = [
            'seller_name' => $prefix.' NAME',
            'seller_voen' => 'V'.$token,
            'seller_bank_name' => $prefix.' BANK',
            'seller_iban' => 'AZ00'.$token.'IBAN',
            'seller_bank_code' => 'C'.$token,
            'seller_bank_voen' => 'BV'.$token,
            'seller_swift' => 'S'.$token,
        ];

        config([
            'invoice.seller.name' => $snapshot['seller_name'],
            'invoice.seller.voen' => $snapshot['seller_voen'],
            'invoice.seller.bank_name' => $snapshot['seller_bank_name'],
            'invoice.seller.iban' => $snapshot['seller_iban'],
            'invoice.seller.bank_code' => $snapshot['seller_bank_code'],
            'invoice.seller.bank_voen' => $snapshot['seller_bank_voen'],
            'invoice.seller.swift' => $snapshot['seller_swift'],
        ]);

        Organization::query()->updateOrCreate(
            ['singleton_key' => Organization::SINGLETON_KEY],
            [
                'name' => $snapshot['seller_name'],
                'voen' => $snapshot['seller_voen'],
                'bank_name' => $snapshot['seller_bank_name'],
                'iban' => $snapshot['seller_iban'],
                'bank_code' => $snapshot['seller_bank_code'],
                'bank_voen' => $snapshot['seller_bank_voen'],
                'swift' => $snapshot['seller_swift'],
            ],
        );

        return $snapshot;
    }

    /** @param array<string, string> $snapshot */
    private function assertSellerSnapshot(Invoice $invoice, array $snapshot): void
    {
        foreach ($snapshot as $field => $value) {
            $this->assertSame($value, $invoice->getAttribute($field), $field);
        }
    }
}
