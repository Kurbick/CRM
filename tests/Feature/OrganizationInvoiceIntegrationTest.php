<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class OrganizationInvoiceIntegrationTest extends AuthorizationTestCase
{
    public function test_web_and_api_invoices_snapshot_organization_a_then_updated_organization_b(): void
    {
        $this->organization('SELLER A');
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        [$webCompany, $webContract] = $this->companyAndContract('WEB');
        $this->post(route('invoices.store'), $this->webPayload($webCompany, $webContract, 'WEB-A'))
            ->assertRedirect();
        $first = Invoice::query()->where('invoice_number', 'WEB-A')->firstOrFail();

        $this->organization('SELLER B');
        [$apiCompany, $apiContract] = $this->companyAndContract('API');
        $this->postJson(
            route('api.companies.invoices.store', $apiCompany),
            $this->apiPayload($apiContract, 'API-B'),
        )->assertCreated();
        $second = Invoice::query()->where('invoice_number', 'API-B')->firstOrFail();

        $this->assertSame('SELLER A', $first->seller_name);
        $this->assertSame('SELLER B', $second->seller_name);
        $this->assertSame('SELLER A', $first->fresh()->seller_name);
    }

    public function test_missing_organization_blocks_web_and_api_without_creating_invoice(): void
    {
        Organization::query()->delete();
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
        ]);
        [$webCompany, $webContract] = $this->companyAndContract('MISSING-WEB');

        $this->from(route('invoices.create'))
            ->post(route('invoices.store'), $this->webPayload($webCompany, $webContract, 'MISSING-WEB-INVOICE'))
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors('organization');

        [$apiCompany, $apiContract] = $this->companyAndContract('MISSING-API');
        $this->postJson(
            route('api.companies.invoices.store', $apiCompany),
            $this->apiPayload($apiContract, 'MISSING-API-INVOICE'),
        )->assertUnprocessable()->assertJsonValidationErrors('organization');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_direct_create_invoice_uses_organization_and_does_not_accept_client_seller_values(): void
    {
        $organization = $this->organization('SERVER SELLER');
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        [$company, $contract] = $this->companyAndContract('SPOOF');

        $response = $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->apiPayload($contract, 'SPOOF-INVOICE') + ['seller_name' => 'CLIENT SELLER'],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('seller_name');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame('SERVER SELLER', $organization->fresh()->name);
    }

    /** @return array<string, mixed> */
    private function webPayload(Company $company, Contract $contract, string $number): array
    {
        $order = $this->subjectOrder($contract, ['price' => '10.00']);

        return [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'lines' => [[
                'description' => 'Organization test line',
                'amount' => '10.00',
                'order_id' => $order->id,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function apiPayload(Contract $contract, string $number): array
    {
        return [
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '10.00',
            'lines' => [[
                'description' => 'Organization test line',
                'amount' => '10.00',
            ]],
        ];
    }

    /** @return array{0: Company, 1: Contract} */
    private function companyAndContract(string $prefix): array
    {
        $company = Company::query()->create([
            'name' => $prefix.' company',
            'status' => 'active',
        ]);
        $contract = Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => $prefix.'-CONTRACT',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        return [$company, $contract];
    }

    private function organization(string $name): Organization
    {
        $token = strtoupper(substr(hash('sha256', $name), 0, 8));

        return Organization::query()->updateOrCreate(
            ['singleton_key' => Organization::SINGLETON_KEY],
            [
                'name' => $name,
                'voen' => 'V'.$token,
                'bank_name' => $name.' BANK',
                'iban' => 'AZ00'.$token.'IBAN',
                'bank_code' => 'C'.$token,
                'bank_voen' => 'BV'.$token,
                'swift' => 'S'.$token,
            ],
        );
    }
}
