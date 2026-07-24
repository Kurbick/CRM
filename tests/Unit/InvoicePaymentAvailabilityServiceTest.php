<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentAvailabilityService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InvoicePaymentAvailabilityServiceTest extends TestCase
{
    private InvoicePaymentAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoicePaymentAvailabilityService();
    }

    public function test_full_amount_is_available_without_payments(): void
    {
        $this->assertAvailability($this->invoice('600.00'), 60000, 0, 60000, '600.00');
    }

    public function test_confirmed_payment_reduces_remaining_and_available(): void
    {
        $this->assertAvailability(
            $this->invoice('600.00', [['confirmed', '500.00']]),
            10000,
            0,
            10000,
            '100.00'
        );
    }

    public function test_pending_reduces_only_available_amount(): void
    {
        $this->assertAvailability(
            $this->invoice('600.00', [['pending', '500.00']]),
            60000,
            50000,
            10000,
            '100.00'
        );
    }

    public function test_confirmed_and_pending_are_kept_separate(): void
    {
        $this->assertAvailability(
            $this->invoice('1000.00', [['confirmed', '400.00'], ['pending', '300.00']]),
            60000,
            30000,
            30000,
            '300.00'
        );
    }

    public function test_multiple_pending_payments_are_summed(): void
    {
        $this->assertAvailability(
            $this->invoice('600.00', [['pending', '300.00'], ['pending', '200.00']]),
            60000,
            50000,
            10000,
            '100.00'
        );
    }

    public function test_cancelled_payments_are_ignored(): void
    {
        $this->assertAvailability(
            $this->invoice('600.00', [['cancelled', '500.00']]),
            60000,
            0,
            60000,
            '600.00'
        );
    }

    public function test_available_amount_never_becomes_negative(): void
    {
        $this->assertAvailability(
            $this->invoice('600.00', [['pending', '700.00']]),
            60000,
            70000,
            0,
            '0.00'
        );
    }

    public function test_decimal_conversion_and_sum_use_exact_minor_units(): void
    {
        $this->assertSame(125050, $this->service->toMinorUnits('1250.50'));
        $this->assertSame(3003, $this->service->sumToMinorUnits(['10.01', '20.02']));
        $this->assertSame('1250.50', $this->service->fromMinorUnits(125050));
        $this->assertSame('1 250,50 ₼', $this->service->formatMinorUnits(125050));

        $source = file_get_contents(app_path('Services/InvoicePaymentAvailabilityService.php'));
        $this->assertStringNotContainsString('(float)', $source);
    }

    /** @param list<array{string, string}> $payments */
    private function invoice(string $total, array $payments = []): Invoice
    {
        $invoice = new Invoice(['total_amount' => $total]);
        $invoice->id = 1;
        $invoice->exists = true;
        $invoice->setRelation('payments', new Collection(array_map(
            fn(array $payment): Payment => new Payment([
                'status' => $payment[0],
                'amount' => $payment[1],
            ]),
            $payments
        )));

        return $invoice;
    }

    private function assertAvailability(
        Invoice $invoice,
        int $remaining,
        int $pending,
        int $available,
        string $amount
    ): void {
        $result = $this->service->evaluate($invoice);

        $this->assertSame($remaining, $result['remaining_minor']);
        $this->assertSame($pending, $result['pending_minor']);
        $this->assertSame($available, $result['available_minor']);
        $this->assertSame($amount, $result['available_amount']);
    }
}
