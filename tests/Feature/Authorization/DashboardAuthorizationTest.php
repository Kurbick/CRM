<?php

namespace Tests\Feature\Authorization;

use App\Models\Company;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use Tests\Support\DomainQueryRecorder;

class DashboardAuthorizationTest extends AuthorizationTestCase
{
    private const COMPANY_NAME = 'DASHBOARD-COMPANY-MARKER';

    private const CONTRACT_NUMBER = 'DASHBOARD-CONTRACT-MARKER';

    private const ORDER_TITLE = 'DASHBOARD-ORDER-MARKER';

    private const SUBSCRIPTION_TITLE = 'DASHBOARD-SUBSCRIPTION-MARKER';

    private const INVOICE_NUMBER = 'DASHBOARD-INVOICE-MARKER';

    private const PAYMENT_MARKER = 'DASHBOARD-PAYMENT-MARKER';

    /** @var list<string> */
    private array $companySecrets = [
        'DASH-VOEN-SECRET',
        'AZ00-DASHBOARD-IBAN-SECRET',
        'DASHBOARD-LEGAL-SECRET',
        'DASHBOARD-ACTUAL-SECRET',
        '+994-DASHBOARD-PHONE',
        'dashboard-secret@example.test',
        'DASHBOARD-COMPANY-COMMENT',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDisclosureFixture();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_user_without_dashboard_permission_is_forbidden_before_domain_queries(): void
    {
        $this->actingAsPermissions([PermissionName::CompaniesView->value]);

        $capture = $this->captureDashboardRequest();

        $capture['result']->assertForbidden();
        $this->assertSame([], $capture['records']);
    }

    public function test_dashboard_only_user_sees_neutral_shell_without_domain_queries_or_markers(): void
    {
        $this->actingAsPermissions([PermissionName::DashboardView->value]);

        $capture = $this->captureDashboardRequest();

        $capture['result']
            ->assertOk()
            ->assertSee('data-testid="dashboard-neutral-fallback"', false)
            ->assertSee('Для просмотра показателей у вас нет необходимых прав')
            ->assertDontSee(self::COMPANY_NAME)
            ->assertDontSee(self::CONTRACT_NUMBER)
            ->assertDontSee(route('companies.index'), false)
            ->assertDontSee(route('contracts.index'), false)
            ->assertDontSee(route('invoices.index'), false);
        $this->assertSame([], $capture['records']);
    }

    public function test_companies_permission_reveals_only_minimal_company_block(): void
    {
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
        ]);

        $capture = $this->captureDashboardRequest();
        $response = $capture['result']->assertOk()->assertSee(self::COMPANY_NAME);

        foreach ($this->companySecrets as $secret) {
            $response->assertDontSee($secret);
        }
        $response
            ->assertDontSee(self::CONTRACT_NUMBER)
            ->assertDontSee(self::SUBSCRIPTION_TITLE)
            ->assertDontSee(self::INVOICE_NUMBER)
            ->assertDontSee(self::PAYMENT_MARKER)
            ->assertDontSee('Общий долг')
            ->assertDontSee('Оплачено')
            ->assertDontSee('Просрочено');

        $tables = DomainQueryRecorder::tables($capture['records']);
        $this->assertContains('companies', $tables);
        $this->assertSame([], array_values(array_intersect(
            ['contracts', 'subscriptions', 'invoices', 'payments', 'credit_balances'],
            $tables
        )));
    }

    public function test_contract_permission_reveals_only_active_subscription_count(): void
    {
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::ContractsView->value,
        ]);

        $capture = $this->captureDashboardRequest();
        $capture['result']
            ->assertOk()
            ->assertSee('Подписки')
            ->assertDontSee(self::COMPANY_NAME)
            ->assertDontSee(self::SUBSCRIPTION_TITLE)
            ->assertDontSee('100.00')
            ->assertDontSee('Выставлено')
            ->assertDontSee('Оплачено');

        $tables = DomainQueryRecorder::tables($capture['records']);
        $this->assertContains('subscriptions', $tables);
        $this->assertSame([], array_values(array_intersect(
            ['companies', 'invoices', 'payments', 'credit_balances'],
            $tables
        )));
    }

    public function test_invoice_and_payment_blocks_are_independently_gated(): void
    {
        $invoiceUser = $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesView->value,
        ]);
        $invoiceCapture = $this->captureDashboardRequest();
        $invoiceCapture['result']
            ->assertOk()
            ->assertSee('Выставлено')
            ->assertSee('Просрочено')
            ->assertDontSee('Оплачено')
            ->assertDontSee('Общий долг')
            ->assertDontSee(self::COMPANY_NAME);
        $this->assertContains('payments', DomainQueryRecorder::tables($invoiceCapture['records']));

        auth()->logout();
        $paymentUser = User::factory()->create();
        $paymentUser->givePermissionTo([
            PermissionName::DashboardView->value,
            PermissionName::PaymentsView->value,
        ]);
        $paymentCapture = $this->actingAs($paymentUser)->captureDashboardRequest();
        $paymentCapture['result']
            ->assertOk()
            ->assertSee('Оплачено')
            ->assertDontSee('Выставлено')
            ->assertDontSee('Просрочено')
            ->assertDontSee('Общий долг')
            ->assertDontSee(self::COMPANY_NAME);
        $this->assertContains('invoices', DomainQueryRecorder::tables($paymentCapture['records']));
        $this->assertNotSame($invoiceUser->id, $paymentUser->id);
    }

    public function test_global_debt_requires_invoice_payment_and_financial_permissions_together(): void
    {
        foreach ([
            [PermissionName::InvoicesView->value, PermissionName::PaymentsView->value],
            [PermissionName::InvoicesView->value, PermissionName::CompaniesFinancialsView->value],
            [PermissionName::PaymentsView->value, PermissionName::CompaniesFinancialsView->value],
        ] as $incomplete) {
            $user = User::factory()->create();
            $user->givePermissionTo([PermissionName::DashboardView->value, ...$incomplete]);
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Общий долг');
        }

        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::DashboardView->value,
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Общий долг')
            ->assertDontSee(self::COMPANY_NAME);
    }

    public function test_administrator_sees_all_blocks_with_legacy_values_and_constant_query_count(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole(SystemRole::Administrator->value);
        $this->actingAs($administrator);

        $oneCompanyCapture = $this->captureDashboardRequest();
        $oneCompanyCount = DomainQueryRecorder::count($oneCompanyCapture['records']);
        foreach (range(1, 9) as $number) {
            $company = $this->company('Dashboard query bound '.$number);
            $contract = $this->contract($company);
            foreach ([1, 2] as $invoiceNumber) {
                $invoice = $this->dashboardInvoice(
                    $company,
                    $contract->id,
                    "QUERY-{$number}-{$invoiceNumber}",
                    'issued',
                    now()->addDays($invoiceNumber)->toDateString(),
                    '50.00'
                );
                $this->dashboardPayment($invoice, 'confirmed', '10.00', now()->subDays($invoiceNumber)->toDateString());
            }
        }
        $tenCompanyCount = DomainQueryRecorder::count($this->captureDashboardRequest()['records']);

        $response = $oneCompanyCapture['result']->assertOk();
        $response
            ->assertSee(self::COMPANY_NAME)
            ->assertSee('Выставлено')
            ->assertSee('500.00')
            ->assertSee('Оплачено')
            ->assertSee('125.00')
            ->assertSee('Общий долг')
            ->assertSee('375.00');
        $this->assertSame(7, $oneCompanyCount);
        $this->assertSame(7, $tenCompanyCount);
    }

    public function test_global_financial_values_follow_canonical_invoice_settlement_semantics(): void
    {
        Payment::query()->delete();
        Invoice::query()->get()->each->delete();
        $company = Company::query()->where('name', self::COMPANY_NAME)->sole();
        $contract = $company->contracts()->firstOrFail();
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $invoices = [
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-DRAFT', 'draft', $yesterday, '100.00'),
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-ISSUED', 'issued', $yesterday, '200.00'),
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-PARTIAL', 'partially_paid', $today, '300.00'),
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-PAID', 'paid', $yesterday, '400.00'),
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-CANCELLED', 'cancelled', $yesterday, '500.00'),
            $this->dashboardInvoice($company, $contract->id, 'LEGACY-FUTURE', 'issued', $tomorrow, '600.00'),
        ];
        $this->dashboardPayment($invoices[1], 'confirmed', '100.00', $today, 'ordinary confirmed');
        $this->dashboardPayment($invoices[1], 'confirmed', '50.00', $today, 'second confirmed');
        $this->dashboardPayment($invoices[1], 'pending', '900.00', $today, 'pending ignored');
        $this->dashboardPayment($invoices[1], 'confirmed', '700.00', $today, 'Legacy Credit Balance transfer');
        $this->dashboardPayment($invoices[1], 'confirmed', '25.00', $today, 'Credit reserve is included');
        $this->dashboardPayment($invoices[3], 'confirmed', '400.00', $today, 'paid invoice settlement');

        $administrator = User::factory()->create();
        $administrator->assignRole(SystemRole::Administrator->value);
        $response = $this->actingAs($administrator)->get(route('dashboard'))->assertOk();
        $overview = $response->viewData('overview');

        $this->assertSame('1500.00', number_format((float) $overview['total_invoiced'], 2, '.', ''));
        $this->assertSame('600.00', number_format((float) $overview['total_paid'], 2, '.', ''));
        $this->assertSame('900.00', number_format((float) $overview['total_debt'], 2, '.', ''));
        $this->assertSame(0, $overview['overdue_count']);
        $this->assertSame('0.00', number_format((float) $overview['overdue_amount'], 2, '.', ''));
        $this->assertSame(1, $overview['active_companies']);
        $this->assertSame(1, $overview['active_subscriptions']);
    }

    public function test_legacy_company_row_aggregates_handle_empty_multiple_and_tied_records(): void
    {
        $empty = $this->company('DASH-EMPTY-COMPANY');
        $populated = $this->company('DASH-POPULATED-COMPANY');
        $contract = $this->contract($populated);
        $due = now()->subDay()->toDateString();
        $later = now()->addDay()->toDateString();
        $first = $this->dashboardInvoice($populated, $contract->id, 'ROW-FIRST', 'issued', $due, '100.00');
        $this->dashboardInvoice($populated, $contract->id, 'ROW-TIED', 'issued', $due, '110.00');
        $this->dashboardInvoice($populated, $contract->id, 'ROW-LATER', 'partially_paid', $later, '90.00');
        $paid = $this->dashboardInvoice($populated, $contract->id, 'ROW-PAID', 'paid', now()->subDays(2)->toDateString(), '200.00');
        $this->dashboardInvoice($populated, $contract->id, 'ROW-CANCELLED', 'cancelled', $due, '999.00');
        $this->dashboardPayment($first, 'confirmed', '40.00', now()->subDay()->toDateString(), 'row payment one');
        $this->dashboardPayment($first, 'confirmed', '60.00', now()->toDateString(), 'row payment two');
        $this->dashboardPayment($first, 'confirmed', '5.00', now()->toDateString(), 'row payment tied');
        $this->dashboardPayment($first, 'pending', '500.00', now()->addDay()->toDateString(), 'row pending');
        $this->dashboardPayment($paid, 'confirmed', '200.00', now()->subDays(2)->toDateString(), 'paid settlement');

        $user = $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $rows = $response->viewData('companies');
        $emptyRow = $rows->firstWhere('name', $empty->name);
        $populatedRow = $rows->firstWhere('name', $populated->name);

        $this->assertSame('0.00', number_format((float) $emptyRow['total_debt'], 2, '.', ''));
        $this->assertFalse($emptyRow['has_overdue']);
        $this->assertNull($emptyRow['last_payment_date']);
        $this->assertNull($emptyRow['next_due_date']);
        $this->assertSame('200.00', number_format((float) $populatedRow['total_debt'], 2, '.', ''));
        $this->assertTrue($populatedRow['has_overdue']);
        $this->assertSame(now()->toDateString(), $populatedRow['last_payment_date']->toDateString());
        $this->assertSame($due, (string) $populatedRow['next_due_date']);
        $this->assertSame('110.00', number_format((float) $populatedRow['next_due_amount'], 2, '.', ''));
    }

    private function createDisclosureFixture(): void
    {
        $company = $this->company(self::COMPANY_NAME);
        $company->forceFill([
            'voen' => $this->companySecrets[0],
            'iban' => $this->companySecrets[1],
            'legal_address' => $this->companySecrets[2],
            'actual_address' => $this->companySecrets[3],
            'phone' => $this->companySecrets[4],
            'email' => $this->companySecrets[5],
            'comment' => $this->companySecrets[6],
        ])->save();
        CreditBalance::query()->create(['company_id' => $company->id, 'amount' => '9876.54']);
        $contract = $this->contract($company);
        $contract->update(['contract_number' => self::CONTRACT_NUMBER]);
        $this->subjectOrder($contract, ['title' => self::ORDER_TITLE, 'price' => '777.77']);
        $this->subjectSubscription($contract, [
            'title' => self::SUBSCRIPTION_TITLE,
            'amount' => '888.88',
            'status' => 'active',
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => self::INVOICE_NUMBER,
            'issue_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'total_amount' => '500.00',
            'status' => 'issued',
        ]);
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $company->id,
            'payment_date' => now()->toDateString(),
            'amount' => '125.00',
            'payment_method' => 'transfer',
            'status' => 'confirmed',
            'comment' => self::PAYMENT_MARKER,
        ]);
    }

    private function dashboardInvoice(
        Company $company,
        int $contractId,
        string $number,
        string $status,
        string $dueDate,
        string $amount
    ): Invoice {
        return Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contractId,
            'invoice_number' => $number.'-'.uniqid(),
            'issue_date' => now()->subMonth()->toDateString(),
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'status' => $status,
        ]);
    }

    private function dashboardPayment(
        Invoice $invoice,
        string $status,
        string $amount,
        string $date,
        string $comment = 'dashboard fixture payment'
    ): void {
        Payment::query()->insert([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{result: mixed, records: list<array{sql: string, tables: list<string>}>} */
    private function captureDashboardRequest(): array
    {
        return (new DomainQueryRecorder)->capture(
            fn () => $this->get(route('dashboard'))
        );
    }
}
