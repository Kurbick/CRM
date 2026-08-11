<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Support\Access\PermissionName;
use Tests\Support\DomainQueryRecorder;

class ApiDashboardAuthorizationTest extends AuthorizationTestCase
{
    private const COMPANY_KEYS = ['id', 'name', 'status', 'invoice_mode'];

    private const INVOICE_KEYS = [
        'id',
        'invoice_number',
        'issue_date',
        'due_date',
        'total_amount',
        'paid_amount',
        'remaining',
        'status',
        'is_overdue',
    ];

    private const SUBSCRIPTION_KEYS = [
        'id',
        'title',
        'status',
        'amount',
        'billing_period',
        'next_billing_date',
        'service_type',
    ];

    public function test_permission_denial_precedes_existing_or_missing_company_binding(): void
    {
        $company = $this->dashboardCompany('DASHBOARD-API-DENIED');
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $existing = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.dashboard.company', $company)),
        );
        $missing = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.dashboard.company', ['company' => 1_000_000])),
        );

        $existing['result']->assertForbidden();
        $missing['result']->assertForbidden();
        $this->assertSame($existing['result']->status(), $missing['result']->status());
        $this->assertSame($this->withoutDebugTrace($existing['result']->json()), $this->withoutDebugTrace($missing['result']->json()));
        $this->assertSame([], $existing['records']);
        $this->assertSame([], $missing['records']);
    }

    public function test_exact_dashboard_permission_returns_closed_company_subscription_and_service_type_projections(): void
    {
        $company = $this->dashboardCompany('DASHBOARD-API-PROJECTION');
        $contract = $this->contract($company);
        $serviceType = ServiceType::query()->create([
            'name' => 'Dashboard service',
            'description' => 'SERVICE-TYPE-DESCRIPTION-SECRET',
            'base_price' => '98765.43',
            'type' => 'subscription',
        ]);
        $serviceType->items()->create(['name' => 'SERVICE-TYPE-ITEM-SECRET', 'price' => '91.23']);
        $subscription = $contract->subscriptions()->forceCreate([
            'service_type_id' => $serviceType->id,
            'title' => 'Dashboard subscription',
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
            'billing_period' => 'monthly',
            'amount' => '125.00',
            'payment_terms' => 30,
            'status' => 'active',
            'comment' => 'SUBSCRIPTION-COMMENT-SECRET',
        ]);
        $invoice = $this->dashboardInvoice($company, '100.00');
        $confirmed = $this->dashboardPayment($invoice, 'confirmed', 'CONFIRMED-PAYMENT-SECRET', '25.00');
        $pending = $this->dashboardPayment($invoice, 'pending', 'PENDING-PAYMENT-SECRET', '60.00');
        $user = $this->actingAsPermissions([PermissionName::DashboardView->value]);

        $response = $this->getJson(route('api.dashboard.company', $company))->assertOk();
        $payload = $response->json();

        $this->assertSame(['company', 'total_debt', 'invoices', 'subscriptions'], array_keys($payload));
        $this->assertSame(self::COMPANY_KEYS, array_keys($payload['company']));
        $this->assertSame(self::INVOICE_KEYS, array_keys($payload['invoices'][0]));
        $this->assertSame(self::SUBSCRIPTION_KEYS, array_keys($payload['subscriptions'][0]));
        $this->assertSame(['id', 'name'], array_keys($payload['subscriptions'][0]['service_type']));
        $response
            ->assertJsonPath('company.id', $company->id)
            ->assertJsonPath('company.name', $company->name)
            ->assertJsonPath('invoices.0.paid_amount', '25.00')
            ->assertJsonPath('invoices.0.remaining', 75)
            ->assertJsonPath('total_debt', '75.00')
            ->assertJsonPath('subscriptions.0.id', $subscription->id)
            ->assertJsonPath('subscriptions.0.amount', '125.00')
            ->assertJsonPath('subscriptions.0.next_billing_date', '2026-09-01')
            ->assertJsonPath('subscriptions.0.service_type', [
                'id' => $serviceType->id,
                'name' => $serviceType->name,
            ]);
        $this->assertFalse($user->can(PermissionName::CompaniesView->value));
        $this->assertFalse($user->can(PermissionName::PaymentsView->value));

        foreach ([
            $company->bank_name,
            $company->iban,
            $company->swift,
            $company->comment,
            'SUBSCRIPTION-COMMENT-SECRET',
            'SERVICE-TYPE-DESCRIPTION-SECRET',
            'SERVICE-TYPE-ITEM-SECRET',
            $confirmed->comment,
            $pending->comment,
        ] as $secret) {
            $response->assertDontSee((string) $secret);
        }
    }

    public function test_projection_uses_bounded_queries_without_payment_or_service_type_n_plus_one(): void
    {
        $company = $this->dashboardCompany('DASHBOARD-API-QUERIES');
        $contract = $this->contract($company);
        $this->subjectSubscription($contract, ['title' => 'First dashboard subscription']);
        $this->subjectSubscription($contract, ['title' => 'Second dashboard subscription']);
        $this->dashboardPayment($this->dashboardInvoice($company, '100.00'), 'confirmed', 'First payment', '10.00');
        $this->dashboardPayment($this->dashboardInvoice($company, '200.00'), 'confirmed', 'Second payment', '20.00');
        $this->actingAsPermissions([PermissionName::DashboardView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.dashboard.company', $company)),
        );

        $capture['result']->assertOk()->assertJsonCount(2, 'subscriptions')->assertJsonCount(2, 'invoices');
        $this->assertSame(6, DomainQueryRecorder::count($capture['records']));
        $this->assertSame(
            ['companies', 'payments', 'invoices', 'subscriptions', 'contracts', 'service_types'],
            DomainQueryRecorder::tables($capture['records']),
        );
    }

    public function test_exact_permission_reaches_binding_and_missing_company_is_not_found(): void
    {
        $this->actingAsPermissions([PermissionName::DashboardView->value]);

        $this->getJson(route('api.dashboard.company', ['company' => 1_000_000]))->assertNotFound();
    }

    private function dashboardCompany(string $name): Company
    {
        $company = $this->company($name);
        $company->forceFill([
            'bank_name' => 'DASHBOARD-BANK-SECRET',
            'iban' => 'AZ00DASHBOARDIBANSECRET',
            'bank_code' => 'BANK-CODE-SECRET',
            'bank_voen' => 'BANK-VOEN-SECRET',
            'swift' => 'SWIFT-SECRET',
            'legal_address' => 'DASHBOARD-LEGAL-SECRET',
            'actual_address' => 'DASHBOARD-ACTUAL-SECRET',
            'comment' => 'DASHBOARD-COMPANY-COMMENT-SECRET',
        ])->save();

        return $company;
    }

    private function dashboardInvoice(Company $company, string $amount): Invoice
    {
        return $company->invoices()->create([
            'invoice_number' => 'DASHBOARD-API-INVOICE-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => $amount,
            'status' => 'issued',
        ]);
    }

    private function dashboardPayment(Invoice $invoice, string $status, string $comment, string $amount): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-10',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutDebugTrace(array $payload): array
    {
        unset($payload['trace']);

        return $payload;
    }
}
