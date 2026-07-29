<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\Subscription;
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

    protected function subjectServiceType(string $type): ServiceType
    {
        return ServiceType::query()->create([
            'name' => 'Authorization '.$type.' '.uniqid(),
            'base_price' => '100.00',
            'type' => $type,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function subjectOrder(Contract $contract, array $overrides = []): Order
    {
        return $contract->orders()->create([
            'service_type_id' => $this->subjectServiceType('one_time')->id,
            'title' => 'Authorization order',
            'order_date' => '2026-08-01',
            'price' => '100.00',
            'payment_terms' => 30,
            'status' => 'in_progress',
            'comment' => 'Original order comment',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function subjectSubscription(Contract $contract, array $overrides = []): Subscription
    {
        return $contract->subscriptions()->create([
            'service_type_id' => $this->subjectServiceType('subscription')->id,
            'title' => 'Authorization subscription',
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
            'billing_period' => 'monthly',
            'amount' => '100.00',
            'payment_terms' => 30,
            'status' => 'active',
            'comment' => 'Original subscription comment',
            ...$overrides,
        ]);
    }

    /**
     * @return array{invoice: Invoice, line: InvoiceLine, payment: Payment, allocation: PaymentAllocation}
     */
    protected function subjectFinancialChain(Order|Subscription $subject, string $invoiceStatus = 'issued'): array
    {
        $contract = $subject->contract;
        $invoice = Invoice::query()->create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'invoice_number' => 'SUBJECT-CHAIN-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => $invoiceStatus,
        ]);
        $line = $invoice->lines()->create([
            'order_id' => $subject instanceof Order ? $subject->id : null,
            'subscription_id' => $subject instanceof Subscription ? $subject->id : null,
            'description' => $subject->title,
            'amount' => '100.00',
            'period_start' => $subject instanceof Subscription ? '2026-08-01' : null,
            'period_end' => $subject instanceof Subscription ? '2026-08-31' : null,
        ]);
        $payment = $this->payment($invoice, 'confirmed', 'Subject chain payment');
        $allocation = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('invoice_line_id', $line->id)
            ->sole();

        return compact('invoice', 'line', 'payment', 'allocation');
    }

    /** @param array{invoice: Invoice, line: InvoiceLine, payment: Payment, allocation: PaymentAllocation} $chain */
    protected function assertSubjectFinancialChainExists(Order|Subscription $subject, array $chain): void
    {
        $this->assertDatabaseHas($subject->getTable(), ['id' => $subject->id]);
        $this->assertDatabaseHas('invoices', ['id' => $chain['invoice']->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $chain['line']->id]);
        $this->assertDatabaseHas('payments', ['id' => $chain['payment']->id]);
        $this->assertDatabaseHas('payment_allocations', ['id' => $chain['allocation']->id]);
    }

    /** @return array{contract: Contract, markers: list<string>} */
    protected function subjectDisclosureContext(string $prefix): array
    {
        $company = $this->company($prefix.' company');
        $company->forceFill([
            'voen' => $prefix.'-VOEN',
            'iban' => 'AZ00'.$prefix.'IBAN',
            'legal_address' => $prefix.'-LEGAL-ADDRESS',
            'actual_address' => $prefix.'-ACTUAL-ADDRESS',
            'phone' => '+994'.$prefix.'12345',
            'email' => strtolower($prefix).'@secret.example.test',
            'website' => 'https://'.$prefix.'.secret.example.test',
            'comment' => $prefix.'-COMPANY-COMMENT',
        ])->save();
        $company->creditBalance()->create(['amount' => '98765.43']);
        $contract = $this->contract($company);
        $otherOrder = $this->subjectOrder($contract, ['title' => $prefix.'-OTHER-ORDER']);
        $otherSubscription = $this->subjectSubscription($contract, ['title' => $prefix.'-OTHER-SUBSCRIPTION']);
        $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => $prefix.'-SECRET-DOCUMENT.pdf',
            'file_path' => 'contracts/'.$prefix.'-secret-document.pdf',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => $prefix.'-SECRET-INVOICE',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'order_id' => $otherOrder->id,
            'description' => $prefix.'-SECRET-INVOICE-LINE',
            'amount' => '100.00',
        ]);
        $payment = $this->payment($invoice, 'pending', $prefix.'-SECRET-PAYMENT');

        return [
            'contract' => $contract,
            'markers' => [
                $company->voen,
                $company->iban,
                $company->legal_address,
                $company->actual_address,
                $company->phone,
                $company->email,
                $company->website,
                $company->comment,
                '98765.43',
                $otherOrder->title,
                $otherSubscription->title,
                $prefix.'-SECRET-DOCUMENT.pdf',
                $invoice->invoice_number,
                $prefix.'-SECRET-INVOICE-LINE',
                $payment->comment,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function orderPayload(string $name = 'New authorization order'): array
    {
        return [
            'service_name' => $name,
            'order_date' => '2026-08-10',
            'price' => '125.00',
            'payment_terms' => 14,
            'status' => 'in_progress',
            'comment' => 'New order comment',
        ];
    }

    /** @return array<string, mixed> */
    protected function subscriptionPayload(string $name = 'New authorization subscription'): array
    {
        return [
            'service_name' => $name,
            'start_date' => '2026-08-10',
            'billing_period' => 'monthly',
            'amount' => '125.00',
            'payment_terms' => 14,
            'status' => 'active',
            'comment' => 'New subscription comment',
        ];
    }

    /** @return array<string, string|int|null> */
    protected function contractPayload(
        Company $company,
        string $number = 'AUTH-CONTRACT'
    ): array {
        return [
            'company_id' => $company->id,
            'contract_number' => $number.'-'.uniqid(),
            'start_date' => '2026-08-01',
            'end_date' => '2027-08-01',
            'status' => 'active',
            'comment' => 'Authorization contract comment',
        ];
    }

    protected function contact(
        Company $company,
        string $firstName = 'Authorization Contact'
    ): CompanyContact {
        return $company->contacts()->create([
            'first_name' => $firstName,
            'last_name' => 'Original',
            'position' => 'Manager',
            'phone' => '+994500000001',
            'email' => 'contact@example.test',
            'role' => 'manager',
            'comment' => 'Original contact comment',
        ]);
    }

    /** @return array<string, string> */
    protected function companyPayload(string $name = 'Authorization New Company'): array
    {
        return [
            'type' => 'company',
            'name' => $name,
            'short_name' => 'Auth Co',
            'voen' => 'AUTH-'.uniqid(),
            'bank_name' => 'Authorization Bank',
            'iban' => 'AZ00AUTHORIZATION000000000000',
            'bank_code' => 'AUTH01',
            'bank_voen' => 'BANK-AUTH',
            'swift' => 'AUTHAZ22',
            'legal_address' => 'Authorization legal address',
            'actual_address' => 'Authorization actual address',
            'email' => 'company@example.test',
            'phone' => '+994500000002',
            'website' => 'https://example.test',
            'status' => 'active',
            'invoice_mode' => 'separate',
            'comment' => 'Authorization company comment',
        ];
    }

    /** @return array<string, string> */
    protected function contactPayload(string $firstName = 'Updated Contact'): array
    {
        return [
            'first_name' => $firstName,
            'last_name' => 'Updated',
            'position' => 'Director',
            'phone' => '+994500000003',
            'email' => 'updated@example.test',
            'role' => 'director',
            'comment' => 'Updated contact comment',
        ];
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
