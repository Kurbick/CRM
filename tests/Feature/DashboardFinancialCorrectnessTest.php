<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Models\Company;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainQueryRecorder;

class DashboardFinancialCorrectnessTest extends FinancialTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->assignRole(SystemRole::Administrator->value);
        $this->authenticatedUser->givePermissionTo(PermissionName::DashboardView->value);
    }

    public function test_web_and_api_use_the_same_canonical_financial_matrix(): void
    {
        $company = $this->company('Canonical matrix');
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();
        $future = now()->addDay()->toDateString();

        $this->invoice($company, '100.00', 'draft', $yesterday);
        $this->invoice($company, '100.00', 'cancelled', $yesterday);
        $this->invoice($company, '100.00', 'issued', $yesterday);

        $partial = $this->invoice($company, '100.00', 'partially_paid', $yesterday);
        $this->payment($partial, '30.00', 'confirmed');
        $this->payment($partial, '50.00', 'pending');

        $full = $this->invoice($company, '100.00', 'paid', $yesterday);
        $this->payment($full, '100.00', 'confirmed');

        $overpaid = $this->invoice($company, '100.00', 'paid', $future);
        $this->payment($overpaid, '110.00', 'confirmed');

        $credit = $this->invoice($company, '100.00', 'partially_paid', $future);
        $this->payment($credit, '30.00', 'confirmed', 'Applied from Credit Balance');

        $mixed = $this->invoice($company, '100.00', 'partially_paid', $future);
        $this->payment($mixed, '40.00', 'confirmed');
        $this->payment($mixed, '30.00', 'confirmed', 'Credit Balance settlement');

        $this->invoice($company, '100.00', 'issued', $today);
        $cancelledPayment = $this->invoice($company, '100.00', 'issued', $future);
        $this->payment($cancelledPayment, '90.00', 'cancelled');

        $api = $this->getJson(route('api.dashboard'))->assertOk()->json();
        $this->assertMoney('800.00', $api['total_invoiced']);
        $this->assertMoney('330.00', $api['total_paid']);
        $this->assertMoney('470.00', $api['total_debt']);
        $this->assertSame(2, $api['overdue_count']);
        $this->assertMoney('170.00', $api['overdue_amount']);

        $web = $this->get(route('dashboard'))->assertOk()->viewData('overview');
        $this->assertMoney('800.00', $web['total_invoiced']);
        $this->assertMoney('330.00', $web['total_paid']);
        $this->assertMoney('470.00', $web['total_debt']);
        $this->assertSame(2, $web['overdue_count']);
        $this->assertMoney('170.00', $web['overdue_amount']);

        $detail = $this->getJson(route('api.dashboard.company', $company))->assertOk()->json();
        $this->assertCount(8, $detail['invoices']);
        $this->assertMoney('470.00', $detail['total_debt']);
        $overpaymentSummary = collect($detail['invoices'])->firstWhere('id', $overpaid->id);
        $this->assertSame('100.00', $overpaymentSummary['paid_amount']);
        $this->assertMoney('0.00', $overpaymentSummary['remaining']);
        $this->assertFalse($overpaymentSummary['is_overdue']);
    }

    public function test_real_confirmed_overpayment_and_cancellation_are_reflected(): void
    {
        $company = $this->company('Top-up cancellation');
        $invoice = $this->invoice($company, '100.00', 'issued', now()->addDay()->toDateString(), true);
        $payment = app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => now()->toDateString(),
            'amount' => '110.00',
            'payment_method' => 'transfer',
        ]);

        $this->assertApiOverview('100.00', '100.00', '0.00');

        app(CancelPayment::class)->execute($payment, 'Dashboard top-up reversal proof');

        $this->assertApiOverview('100.00', '0.00', '100.00');
    }

    public function test_real_credit_application_and_reversal_are_reflected(): void
    {
        $company = $this->company('Credit reversal');
        $invoice = $this->invoice($company, '100.00', 'issued', now()->addDay()->toDateString(), true);
        CreditBalance::query()->create(['company_id' => $company->id, 'amount' => '30.00']);

        $result = app(ApplyCreditToInvoice::class)->execute($invoice, 3000);
        $this->assertTrue($result->applied);
        $payment = Payment::query()->findOrFail($result->paymentId);
        $this->assertApiOverview('100.00', '30.00', '70.00');

        app(CancelPayment::class)->execute($payment, 'Dashboard Credit reversal proof');

        $this->assertApiOverview('100.00', '0.00', '100.00');
    }

    public function test_company_financial_queries_remain_bounded_as_fixtures_grow(): void
    {
        $first = $this->company('Bounded first');
        $this->payment(
            $this->invoice($first, '100.00', 'partially_paid', now()->subDay()->toDateString()),
            '30.00',
            'confirmed',
        );
        $oneCompanyCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.dashboard.companies'))->assertOk(),
        );
        $oneCompanyCount = DomainQueryRecorder::count($oneCompanyCapture['records']);

        foreach (range(2, 10) as $number) {
            $company = $this->company('Bounded '.$number);
            $this->payment(
                $this->invoice($company, '100.00', 'partially_paid', now()->subDay()->toDateString()),
                '30.00',
                'confirmed',
            );
        }

        $tenCompanyCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.dashboard.companies'))->assertOk(),
        );
        $tenCompanyCount = DomainQueryRecorder::count($tenCompanyCapture['records']);

        $this->assertSame($oneCompanyCount, $tenCompanyCount);
        $this->assertLessThanOrEqual(5, $tenCompanyCount);
        $this->assertCount(10, $tenCompanyCapture['result']->json());
        $this->assertApiOverview('1000.00', '300.00', '700.00');
    }

    private function company(string $name): Company
    {
        return Company::query()->create(['name' => $name, 'status' => 'active']);
    }

    private function invoice(
        Company $company,
        string $amount,
        string $status,
        string $dueDate,
        bool $withLine = false,
    ): Invoice {
        $invoice = $company->invoices()->create([
            'invoice_number' => 'DASH-FIN-'.uniqid(),
            'issue_date' => now()->subMonth()->toDateString(),
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'status' => $status,
        ]);

        if ($withLine) {
            $invoice->lines()->create([
                'description' => 'Dashboard financial line',
                'amount' => $amount,
            ]);
        }

        return $invoice;
    }

    private function payment(
        Invoice $invoice,
        string $amount,
        string $status,
        ?string $comment = null,
    ): Payment {
        return Payment::withoutEvents(fn (): Payment => $invoice->payments()->create([
            'company_id' => $invoice->company_id,
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]));
    }

    private function assertApiOverview(string $invoiced, string $paid, string $debt): void
    {
        $payload = $this->getJson(route('api.dashboard'))->assertOk()->json();

        $this->assertMoney($invoiced, $payload['total_invoiced']);
        $this->assertMoney($paid, $payload['total_paid']);
        $this->assertMoney($debt, $payload['total_debt']);
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame($expected, number_format((float) $actual, 2, '.', ''));
    }
}
