<?php

namespace Tests\Unit;

use App\Models\CreditBalanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentSourceResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InvoicePaymentSourceResolverTest extends TestCase
{
    private InvoicePaymentSourceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new InvoicePaymentSourceResolver();
    }

    public function test_normal_confirmed_payment_has_no_balance_state(): void
    {
        $result = $this->resolver->fromLoadedInvoice($this->invoice([
            $this->payment(10, 'confirmed', '500.00', '500.00'),
        ]));

        $this->assertSame(50000, $result['total_applied_minor']);
        $this->assertSame(0, $result['credit_balance_applied_minor']);
        $this->assertSame('0.00', $result['credit_balance_applied_amount']);
        $this->assertNull($result['state']);
        $this->assertSame([], $result['credit_balance_payment_ids']);
    }

    public function test_all_applied_money_from_balance_has_full_state_even_when_invoice_is_partially_paid(): void
    {
        $result = $this->resolver->fromLoadedInvoice($this->invoice([
            $this->payment(10, 'confirmed', '150.00', '100.00', ['applied']),
        ]));

        $this->assertSame(10000, $result['total_applied_minor']);
        $this->assertSame(10000, $result['credit_balance_applied_minor']);
        $this->assertSame('100.00', $result['credit_balance_applied_amount']);
        $this->assertSame('full', $result['state']);
        $this->assertSame([10], $result['credit_balance_payment_ids']);
    }

    public function test_mixed_allocations_have_partial_state(): void
    {
        $result = $this->resolver->fromLoadedInvoice($this->invoice([
            $this->payment(10, 'confirmed', '20.00', '20.00', ['applied']),
            $this->payment(11, 'confirmed', '480.00', '480.00'),
        ]));

        $this->assertSame(50000, $result['total_applied_minor']);
        $this->assertSame(2000, $result['credit_balance_applied_minor']);
        $this->assertSame('20.00', $result['credit_balance_applied_amount']);
        $this->assertSame('partial', $result['state']);
        $this->assertSame([10], $result['credit_balance_payment_ids']);
    }

    public function test_pending_cancelled_unallocated_overpayment_and_reversed_application_are_excluded(): void
    {
        $result = $this->resolver->fromLoadedInvoice($this->invoice([
            $this->payment(10, 'pending', '10.00', '10.00', ['applied']),
            $this->payment(11, 'cancelled', '10.00', '10.00', ['applied']),
            $this->payment(12, 'confirmed', '30.00', null, ['applied']),
            $this->payment(13, 'confirmed', '40.00', '40.00', ['applied', 'applied_reversal']),
            $this->payment(14, 'confirmed', '130.00', '100.00'),
        ]));

        $this->assertSame(14000, $result['total_applied_minor']);
        $this->assertSame(0, $result['credit_balance_applied_minor']);
        $this->assertSame('0.00', $result['credit_balance_applied_amount']);
        $this->assertNull($result['state']);
    }

    public function test_top_up_transfer_from_another_invoice_does_not_mark_payment_as_balance(): void
    {
        $result = $this->resolver->fromLoadedInvoice($this->invoice([
            $this->payment(10, 'confirmed', '50.00', '50.00', ['top_up']),
        ]));

        $this->assertSame(5000, $result['total_applied_minor']);
        $this->assertSame(0, $result['credit_balance_applied_minor']);
        $this->assertSame('0.00', $result['credit_balance_applied_amount']);
        $this->assertNull($result['state']);
    }

    /** @param list<Payment> $payments */
    private function invoice(array $payments): Invoice
    {
        $invoice = new Invoice(['total_amount' => '500.00', 'status' => 'partially_paid']);
        $invoice->id = 1;
        $invoice->exists = true;
        $invoice->setRelation('payments', new Collection($payments));

        return $invoice;
    }

    /** @param list<string> $entryTypes */
    private function payment(
        int $id,
        string $status,
        string $amount,
        ?string $allocatedAmount,
        array $entryTypes = []
    ): Payment {
        $payment = new Payment([
            'invoice_id' => 1,
            'amount' => $amount,
            'status' => $status,
            'payment_method' => 'transfer',
        ]);
        $payment->id = $id;
        $payment->exists = true;

        $allocations = [];
        if ($allocatedAmount !== null) {
            $allocation = new PaymentAllocation([
                'payment_id' => $id,
                'invoice_line_id' => 1,
                'amount' => $allocatedAmount,
            ]);
            $allocation->id = $id;
            $allocation->exists = true;
            $allocations[] = $allocation;
        }

        $entries = array_map(function (string $type) use ($id): CreditBalanceEntry {
            $entry = new CreditBalanceEntry([
                'type' => $type,
                'payment_id' => $id,
                'invoice_id' => 1,
                'amount' => '1.00',
            ]);
            $entry->id = $id;
            $entry->exists = true;

            return $entry;
        }, $entryTypes);

        $payment->setRelation('allocations', new Collection($allocations));
        $payment->setRelation('creditBalanceEntries', new Collection($entries));

        return $payment;
    }
}
