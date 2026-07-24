<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceEditabilityService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceEditabilityServiceTest extends TestCase
{
    private InvoiceEditabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceEditabilityService();
    }

    public static function editableCases(): array
    {
        return [
            'draft without payments' => ['draft', [], false],
            'issued without payments' => ['issued', [], false],
            'draft with pending' => ['draft', ['pending'], true],
            'issued with pending' => ['issued', ['pending'], true],
            'issued with only cancelled' => ['issued', ['cancelled'], false],
        ];
    }

    #[DataProvider('editableCases')]
    public function test_editable_states(string $status, array $paymentStatuses, bool $hasPending): void
    {
        $result = $this->service->evaluate($this->invoice($status, $paymentStatuses));

        $this->assertTrue($result['editable']);
        $this->assertNull($result['reason']);
        $this->assertSame($hasPending, $result['has_pending_payments']);
        $this->assertFalse($result['has_confirmed_payments']);
    }

    public static function blockedStatusCases(): array
    {
        return [
            'partially paid' => ['partially_paid', 'invalid_status'],
            'paid' => ['paid', 'invalid_status'],
            'cancelled' => ['cancelled', 'cancelled'],
            'unknown' => ['archived', 'invalid_status'],
        ];
    }

    #[DataProvider('blockedStatusCases')]
    public function test_non_editable_statuses(string $status, string $reason): void
    {
        $result = $this->service->evaluate($this->invoice($status));

        $this->assertFalse($result['editable']);
        $this->assertSame($reason, $result['reason']);
    }

    public function test_confirmed_payment_blocks_issued_and_draft_including_credit_balance_payment(): void
    {
        foreach (['issued', 'draft'] as $status) {
            $result = $this->service->evaluate($this->invoice($status, ['confirmed']));

            $this->assertFalse($result['editable']);
            $this->assertSame('confirmed_payment', $result['reason']);
            $this->assertTrue($result['has_confirmed_payments']);
        }

        // Credit Balance application creates the same structural confirmed Payment.
        $creditBalanceResult = $this->service->evaluate($this->invoice('issued', ['confirmed']));
        $this->assertFalse($creditBalanceResult['editable']);
        $this->assertSame('confirmed_payment', $creditBalanceResult['reason']);
    }

    private function invoice(string $status, array $paymentStatuses = []): Invoice
    {
        $invoice = new Invoice(['status' => $status]);
        $invoice->id = 1;
        $invoice->exists = true;
        $invoice->setRelation('payments', new Collection(array_map(
            fn(string $paymentStatus): Payment => new Payment(['status' => $paymentStatus]),
            $paymentStatuses
        )));

        return $invoice;
    }
}
