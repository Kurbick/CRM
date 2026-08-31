<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\ServiceType;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\FinancialTestCase as TestCase;

class InvoiceOrganizationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_invoice_uses_active_organization_and_ignores_forged_issuer(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Contract invoice authority');
        $contract = $this->contractFor($company, $zeroLine, 'CONTRACT-ACTIVE-AUTHORITY');
        $order = $this->orderFor($contract);

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->post(route('invoices.store'), $this->contractPayload($company, $contract, $order) + [
                'issuer_organization_id' => $maksim->id,
            ])
            ->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame($zeroLine->id, $invoice->issuer_organization_id);
    }

    public function test_contract_invoice_after_topbar_switch_uses_contract_issuer(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Switched contract invoice authority');
        $contract = $this->contractFor($company, $zeroLine, 'CONTRACT-SWITCH-AUTHORITY');
        $order = $this->orderFor($contract);

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->post(route('organization-context.update'), ['organization_id' => $maksim->id])
            ->assertRedirect();

        $this->post(route('invoices.store'), $this->contractPayload($company, $contract, $order))
            ->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame($zeroLine->id, $invoice->issuer_organization_id);
    }

    public function test_contract_invoice_uses_contract_issuer_when_active_organization_differs(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Contract invoice authority');
        $contract = $this->contractFor($company, $zeroLine, 'CONTRACT-AUTHORITY');
        $order = $this->orderFor($contract);

        $this->withSession(['active_organization_id' => $maksim->id])
            ->post(route('invoices.store'), [
                'company_id' => $company->id,
                'contract_id' => $contract->id,
                'issuer_organization_id' => $maksim->id,
                'invoice_number_sequence' => 1,
                'invoice_number_manual' => 0,
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-31',
                'lines' => [[
                    'description' => 'Contract service',
                    'amount' => '10.00',
                    'order_id' => $order->id,
                ]],
            ])
            ->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame($contract->id, $invoice->contract_id);
        $this->assertSame($zeroLine->id, $invoice->issuer_organization_id);
        $this->assertNotSame($maksim->id, $invoice->issuer_organization_id);
    }

    public function test_contract_bound_create_restores_contract_context_after_active_organization_switch(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Contract context restoration');
        $contract = $this->contractFor($company, $zeroLine, 'CTR-2026-001');
        $order = $this->orderFor($contract);
        $url = route('invoices.create', ['contract_id' => $contract->id]);

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->get($url)
            ->assertOk()
            ->assertSeeText('ZeroLine')
            ->assertSeeText('CTR-2026-001')
            ->assertSee('/ZL-26', false);

        $this->withSession(['active_organization_id' => $zeroLine->id])
            ->from($url)
            ->post(route('organization-context.update'), ['organization_id' => $maksim->id])
            ->assertRedirect($url);

        $this->assertSame($maksim->id, session('active_organization_id'));

        $this->get($url)
            ->assertOk()
            ->assertSeeText('ZeroLine')
            ->assertSeeText('CTR-2026-001')
            ->assertSeeText($company->name)
            ->assertSee('/ZL-26', false)
            ->assertDontSee('name="contract_id" x-model="selectedContractId"', false)
            ->assertDontSee('aria-label="'.__('invoices.form.select_contract').'"', false);

        $this->get(route('ajax.items', ['contract' => $contract->id]))
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id, 'description' => $order->title]);
    }

    public function test_manual_contract_selector_is_scoped_to_active_organization(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Manual contract scope');
        $zeroLineContract = $this->contractFor($company, $zeroLine, 'ZERO-LINE-CONTRACT');
        $maksimContract = $this->contractFor($company, $maksim, 'MAKSIM-CONTRACT');

        $this->withSession(['active_organization_id' => $maksim->id])
            ->get(route('ajax.contracts', ['company' => $company->id]))
            ->assertOk()
            ->assertJsonMissing(['id' => $zeroLineContract->id])
            ->assertJsonFragment(['id' => $maksimContract->id]);
    }

    public function test_contract_store_rejects_a_forged_company_for_the_contract(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Authoritative contract company');
        $forgedCompany = $this->company('Forged invoice company');
        $contract = $this->contractFor($company, $zeroLine, 'AUTHORITATIVE-CONTRACT');
        $order = $this->orderFor($contract);

        $this->from(route('invoices.create', ['contract_id' => $contract->id]))
            ->withSession(['active_organization_id' => $maksim->id])
            ->post(route('invoices.store'), [
                'company_id' => $forgedCompany->id,
                'contract_id' => $contract->id,
                'issuer_organization_id' => $maksim->id,
                'invoice_number_sequence' => 1,
                'invoice_number_manual' => 0,
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-31',
                'lines' => [[
                    'description' => 'Forged company service',
                    'amount' => '10.00',
                    'order_id' => $order->id,
                ]],
            ])
            ->assertRedirect(route('invoices.create', ['contract_id' => $contract->id]))
            ->assertSessionHasErrors('contract_id');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_number_preview_uses_active_contract_and_invoice_issuers_only(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Invoice preview authority');
        $contract = $this->contractFor($company, $zeroLine, 'CONTRACT-PREVIEW');
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $zeroLine->id,
            'invoice_number' => '1/ZL-26',
            'invoice_number_year' => 2026,
            'invoice_number_sequence' => 1,
            'invoice_number_code' => 'ZL',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '10.00',
            'status' => 'draft',
        ]);

        $this->withSession(['active_organization_id' => $maksim->id]);

        $this->get(route('invoices.number-preview', [
            'issue_date' => '2026-08-02',
            'issuer_organization_id' => $zeroLine->id,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 'ME');

        $this->get(route('invoices.number-preview', [
            'issue_date' => '2026-08-02',
            'contract_id' => $contract->id,
            'issuer_organization_id' => $maksim->id,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 'ZL');

        $this->get(route('invoices.number-preview', [
            'issue_date' => '2026-08-02',
            'invoice_id' => $invoice->id,
            'issuer_organization_id' => $maksim->id,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 'ZL');
    }

    public function test_invoice_create_and_edit_show_read_only_issuer_context_in_ru_and_az(): void
    {
        [$zeroLine, $maksim] = $this->organizations();
        $company = $this->company('Invoice issuer presentation');
        $contract = $this->contractFor($company, $zeroLine, 'CONTRACT-PRESENTATION');
        $contract->update([
            'start_date' => '2026-08-01',
            'end_date' => '2028-11-01',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $zeroLine->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'PRESENTATION-1',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '10.00',
            'status' => 'draft',
        ]);

        foreach ([
            'ru' => 'Наша организация',
            'az' => 'Təşkilatımız',
        ] as $locale => $label) {
            $this->withSession(['locale' => $locale, 'active_organization_id' => $maksim->id]);

            $create = $this->get(route('invoices.create', ['contract_id' => $contract->id]))
                ->assertOk()
                ->assertSeeText($label)
                ->assertSeeText('ZeroLine')
                ->assertSeeText('№ CONTRACT-PRESENTATION')
                ->assertDontSeeText(__('invoices.form.validity').' 01.08.2026 — 01.11.2028')
                ->assertDontSee('name="issuer_organization_id"', false)
                ->assertDontSee('name="contract_id" x-model="selectedContractId"', false)
                ->assertDontSee('aria-label="'.__('invoices.form.select_contract').'"', false)
                ->assertDontSee('<select id="issuer_organization_id"', false);

            $this->assertSame(0, substr_count($create->getContent(), 'name="issuer_organization_id"'));

            $this->get(route('invoices.edit', $invoice))
                ->assertOk()
                ->assertSeeText($label)
                ->assertSeeText('ZeroLine')
                ->assertDontSee('name="issuer_organization_id"', false)
                ->assertDontSee('<select id="issuer_organization_id"', false);
        }
    }

    /** @return array{0: Organization, 1: Organization} */
    private function organizations(): array
    {
        $zeroLine = Organization::query()->firstOrFail();
        $zeroLine->update([
            'name' => 'ZeroLine',
            'invoice_number_code' => 'ZL',
            'is_active' => true,
        ]);
        $maksim = Organization::query()->firstOrCreate(
            ['invoice_number_code' => 'ME'],
            [
                'name' => 'Maksim Ermakov',
                'is_active' => true,
            ],
        );
        $maksim->update([
            'name' => 'Maksim Ermakov',
            'is_active' => true,
        ]);

        return [$zeroLine->fresh(), $maksim];
    }

    private function company(string $name): Company
    {
        return Company::query()->create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function contractFor(Company $company, Organization $organization, string $number): Contract
    {
        return Contract::query()->create([
            'company_id' => $company->id,
            'issuer_organization_id' => $organization->id,
            'contract_number' => $number,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function orderFor(Contract $contract): object
    {
        $serviceType = ServiceType::query()->create([
            'name' => 'Contract authority service',
            'base_price' => '10.00',
            'type' => 'one_time',
        ]);

        return $contract->orders()->create([
            'service_type_id' => $serviceType->id,
            'title' => 'Contract service',
            'order_date' => '2026-08-01',
            'price' => '10.00',
            'payment_terms' => 30,
            'status' => 'in_progress',
        ]);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function contractPayload(Company $company, Contract $contract, object $order): array
    {
        return [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number_sequence' => 1,
            'invoice_number_manual' => 0,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'lines' => [[
                'description' => 'Contract service',
                'amount' => '10.00',
                'order_id' => $order->id,
            ]],
        ];
    }
}
