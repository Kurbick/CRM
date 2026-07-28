<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AuthorizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    /** @param list<string> $permissions */
    protected function actingAsPermissions(array $permissions = []): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /** @param list<string> $permissions */
    protected function actingAsCustomRole(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'custom-finance-'.uniqid(),
            'guard_name' => 'web',
            'display_name' => 'Custom finance',
        ]);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'web');

        return $user;
    }

    protected function company(string $name = 'Authorization Company'): Company
    {
        return Company::query()->create([
            'name' => $name,
            'status' => 'active',
            'invoice_mode' => 'separate',
        ]);
    }

    protected function contract(Company $company): Contract
    {
        return Contract::query()->create([
            'company_id' => $company->id,
            'contract_number' => 'AUTH-'.uniqid(),
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    protected function invoice(string $status = 'draft', string $number = 'AUTH-INVOICE'): Invoice
    {
        $company = $this->company($number.' Company');
        $contract = $this->contract($company);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => $number.'-'.uniqid(),
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => '100.00',
            'status' => $status,
            'payer_name' => $company->name,
            'contract_reference' => $contract->contract_number,
        ]);
        $invoice->lines()->create([
            'description' => 'Authorization line',
            'amount' => '100.00',
        ]);

        return $invoice;
    }

    protected function payment(
        Invoice $invoice,
        string $status = 'pending',
        string $comment = 'Authorization payment'
    ): Payment {
        $id = Payment::query()->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-15',
            'amount' => '25.00',
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = Payment::query()->findOrFail($id);

        if ($status === 'confirmed') {
            app(InvoicePaymentAllocationWriter::class)->synchronize($invoice);
        }

        return $payment;
    }

    /** @return array<string, mixed> */
    protected function invoiceUpdatePayload(Invoice $invoice): array
    {
        $line = $invoice->lines()->firstOrFail();

        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'amount' => '100.00',
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    protected function invoiceStorePayload(Company $company, Contract $contract): array
    {
        return [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'AUTH-STORE-'.uniqid(),
            'issue_date' => '2026-07-20',
            'due_date' => '2026-08-20',
            'comment' => 'Authorization store payload',
            'lines' => [[
                'description' => 'Authorization new invoice line',
                'amount' => '120.00',
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]],
        ];
    }

    /** @return array<string, string> */
    protected function validPaymentPayload(string $status): array
    {
        return [
            'payment_date' => '2026-07-20',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => 'Authorization store',
        ];
    }
}
