<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class InvoicePaymentAllocationWriterTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePaymentAllocationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = $this->app->make(InvoicePaymentAllocationWriter::class);
    }

    public function test_unsaved_invoice_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer->synchronize(new Invoice());
    }

    public function test_invoice_without_lines_or_payments_has_empty_result(): void
    {
        $invoice = $this->invoice();

        $result = $this->writer->synchronize($invoice);

        $this->assertSame([], $result['calculation']['allocations']);
        $this->assertSame('0.00', $result['calculation']['totals']['applied_total']);
        $this->assertSame(0, $result['allocation_count']);
        $this->assertSame($this->noChanges(), $result['changes']);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_partial_confirmed_payment_creates_allocation(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, '100.00');
        $payment = $this->payment($invoice, '40.00');

        $result = $this->writer->synchronize($invoice);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment,
            'invoice_line_id' => $line,
            'amount' => '40.00',
        ]);
        $this->assertSame(1, $result['changes']['created']);
    }

    public function test_one_payment_is_distributed_between_lines_in_fifo_order(): void
    {
        $invoice = $this->invoice();
        $newer = $this->line($invoice, '100.00', '2026-06-01');
        $older = $this->line($invoice, '100.00', '2026-05-01');
        $payment = $this->payment($invoice, '130.00');

        $this->writer->synchronize($invoice);

        $this->assertAllocation($payment, $older, '100.00');
        $this->assertAllocation($payment, $newer, '30.00');
    }

    public function test_multiple_confirmed_payments_are_persisted_in_calculator_order(): void
    {
        $invoice = $this->invoice();
        $firstLine = $this->line($invoice, '100.00', '2026-05-01');
        $secondLine = $this->line($invoice, '100.00', '2026-06-01');
        $firstPayment = $this->payment($invoice, '130.00', 'confirmed', '2026-07-01');
        $secondPayment = $this->payment($invoice, '50.00', 'confirmed', '2026-07-02');

        $result = $this->writer->synchronize($invoice);

        $this->assertSame([
            ['payment_id' => $firstPayment, 'invoice_line_id' => $firstLine, 'amount' => '100.00'],
            ['payment_id' => $firstPayment, 'invoice_line_id' => $secondLine, 'amount' => '30.00'],
            ['payment_id' => $secondPayment, 'invoice_line_id' => $secondLine, 'amount' => '50.00'],
        ], $result['calculation']['allocations']);
        $this->assertSame(3, $result['allocation_count']);
    }

    public function test_pending_payment_does_not_create_allocation(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00');
        $this->payment($invoice, '50.00', 'pending');

        $result = $this->writer->synchronize($invoice);

        $this->assertSame(0, $result['allocation_count']);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_cancelled_payment_does_not_create_allocation(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00');
        $this->payment($invoice, '50.00', 'cancelled');

        $result = $this->writer->synchronize($invoice);

        $this->assertSame(0, $result['allocation_count']);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_credit_balance_comment_does_not_exclude_confirmed_payment(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, '100.00');
        $payment = $this->payment(
            $invoice,
            '30.00',
            'confirmed',
            '2026-07-01',
            'Автоматически применён Credit Balance'
        );

        $this->writer->synchronize($invoice);

        $this->assertAllocation($payment, $line, '30.00');
    }

    public function test_overpayment_persists_only_applied_part(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, '100.00');
        $payment = $this->payment($invoice, '130.00');

        $result = $this->writer->synchronize($invoice);

        $this->assertAllocation($payment, $line, '100.00');
        $this->assertSame('30.00', $result['calculation']['payments'][$payment]['unallocated']);
        $this->assertSame('30.00', $result['calculation']['totals']['overpayment_total']);
    }

    public function test_repeated_synchronization_preserves_id_and_timestamps(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00');
        $this->payment($invoice, '40.00');
        $this->writer->synchronize($invoice);
        $before = DB::table('payment_allocations')->first();

        $result = $this->writer->synchronize($invoice);
        $after = DB::table('payment_allocations')->first();

        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'unchanged' => 1,
        ], $result['changes']);
    }

    public function test_changed_confirmed_payment_amount_updates_existing_allocation(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, '100.00');
        $payment = $this->payment($invoice, '40.00');
        $this->writer->synchronize($invoice);
        $allocationId = PaymentAllocation::query()->value('id');
        DB::table('payments')->where('id', $payment)->update(['amount' => '70.00']);

        $result = $this->writer->synchronize($invoice);

        $this->assertDatabaseHas('payment_allocations', [
            'id' => $allocationId,
            'payment_id' => $payment,
            'invoice_line_id' => $line,
            'amount' => '70.00',
        ]);
        $this->assertSame(1, $result['changes']['updated']);
    }

    public function test_changed_payment_date_rebuilds_fifo_allocations(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, '50.00');
        $first = $this->payment($invoice, '50.00', 'confirmed', '2026-07-01');
        $second = $this->payment($invoice, '50.00', 'confirmed', '2026-07-02');
        $this->writer->synchronize($invoice);
        DB::table('payments')->where('id', $second)->update(['payment_date' => '2026-06-30']);

        $result = $this->writer->synchronize($invoice);

        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $first]);
        $this->assertAllocation($second, $line, '50.00');
        $this->assertSame(1, $result['changes']['created']);
        $this->assertSame(1, $result['changes']['deleted']);
    }

    public function test_changed_period_start_rebuilds_line_fifo_allocations(): void
    {
        $invoice = $this->invoice();
        $first = $this->line($invoice, '50.00', '2026-05-01');
        $second = $this->line($invoice, '50.00', '2026-06-01');
        $payment = $this->payment($invoice, '50.00');
        $this->writer->synchronize($invoice);
        DB::table('invoice_lines')->where('id', $second)->update(['period_start' => '2026-04-01']);

        $result = $this->writer->synchronize($invoice);

        $this->assertDatabaseMissing('payment_allocations', ['invoice_line_id' => $first]);
        $this->assertAllocation($payment, $second, '50.00');
        $this->assertSame(1, $result['changes']['created']);
        $this->assertSame(1, $result['changes']['deleted']);
    }

    public function test_cancelled_confirmed_payment_allocations_are_deleted(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00');
        $payment = $this->payment($invoice, '50.00');
        $this->writer->synchronize($invoice);
        DB::table('payments')->where('id', $payment)->update(['status' => 'cancelled']);

        $result = $this->writer->synchronize($invoice);

        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame(1, $result['changes']['deleted']);
    }

    public function test_synchronizing_one_invoice_does_not_change_another_invoice(): void
    {
        $firstInvoice = $this->invoice();
        $firstLine = $this->line($firstInvoice, '100.00');
        $firstPayment = $this->payment($firstInvoice, '20.00');
        $secondInvoice = $this->invoice();
        $secondLine = $this->line($secondInvoice, '100.00');
        $secondPayment = $this->payment($secondInvoice, '30.00');
        $this->writer->synchronize($firstInvoice);
        $this->writer->synchronize($secondInvoice);
        $secondBefore = PaymentAllocation::query()
            ->where('payment_id', $secondPayment)
            ->firstOrFail()
            ->getRawOriginal();

        $this->writer->synchronize($firstInvoice);

        $this->assertAllocation($firstPayment, $firstLine, '20.00');
        $this->assertAllocation($secondPayment, $secondLine, '30.00');
        $this->assertSame(
            $secondBefore,
            PaymentAllocation::query()->where('payment_id', $secondPayment)->firstOrFail()->getRawOriginal()
        );
    }

    public function test_cross_invoice_allocation_is_rejected_without_changes(): void
    {
        $firstInvoice = $this->invoice();
        $firstLine = $this->line($firstInvoice, '100.00');
        $firstPayment = $this->payment($firstInvoice, '50.00');
        $secondInvoice = $this->invoice();
        $secondLine = $this->line($secondInvoice, '100.00');
        DB::table('payment_allocations')->insert([
            'payment_id' => $firstPayment,
            'invoice_line_id' => $secondLine,
            'amount' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->writer->synchronize($firstInvoice);
            $this->fail('Cross-invoice allocation was not rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Invalid payment allocation', $exception->getMessage());
            $this->assertStringContainsString("payment {$firstPayment}", $exception->getMessage());
            $this->assertStringContainsString("invoice line {$secondLine}", $exception->getMessage());
        }

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $firstPayment,
            'invoice_line_id' => $secondLine,
            'amount' => '10.00',
        ]);
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $firstPayment,
            'invoice_line_id' => $firstLine,
        ]);
    }

    public function test_saved_allocation_sum_equals_calculator_applied_total(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00');
        $this->line($invoice, '50.00');
        $this->payment($invoice, '120.00');

        $result = $this->writer->synchronize($invoice);

        $savedMinorUnits = DB::table('payment_allocations')
            ->pluck('amount')
            ->sum(function (string $amount): int {
                [$whole, $fraction] = explode('.', $amount);

                return ((int) $whole * 100) + (int) $fraction;
            });
        [$appliedWhole, $appliedFraction] = explode(
            '.',
            $result['calculation']['totals']['applied_total']
        );

        $this->assertSame(
            ((int) $appliedWhole * 100) + (int) $appliedFraction,
            $savedMinorUnits
        );
    }

    public function test_zero_amounts_do_not_create_allocations(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '0.00');
        $this->payment($invoice, '0.00');

        $result = $this->writer->synchronize($invoice);

        $this->assertSame(0, $result['allocation_count']);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_identical_database_state_returns_identical_calculation(): void
    {
        $invoice = $this->invoice();
        $this->line($invoice, '100.00', '2026-05-01');
        $this->payment($invoice, '40.00');

        $first = $this->writer->synchronize($invoice);
        $second = $this->writer->synchronize($invoice);

        $this->assertSame($first['calculation'], $second['calculation']);
    }

    public function test_validation_exception_rolls_back_without_partial_updates(): void
    {
        $firstInvoice = $this->invoice();
        $firstLine = $this->line($firstInvoice, '100.00');
        $firstPayment = $this->payment($firstInvoice, '40.00');
        $this->writer->synchronize($firstInvoice);
        $correctBefore = PaymentAllocation::query()->firstOrFail()->getRawOriginal();
        DB::table('payments')->where('id', $firstPayment)->update(['amount' => '70.00']);
        $secondInvoice = $this->invoice();
        $secondLine = $this->line($secondInvoice, '100.00');
        DB::table('payment_allocations')->insert([
            'payment_id' => $firstPayment,
            'invoice_line_id' => $secondLine,
            'amount' => '5.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->writer->synchronize($firstInvoice);
            $this->fail('Corrupt allocation was not rejected.');
        } catch (LogicException) {
            // Expected: validation happens before synchronization writes.
        }

        $correctAfter = PaymentAllocation::query()
            ->where('payment_id', $firstPayment)
            ->where('invoice_line_id', $firstLine)
            ->firstOrFail()
            ->getRawOriginal();
        $this->assertSame($correctBefore, $correctAfter);
        $this->assertDatabaseCount('payment_allocations', 2);
    }

    private function invoice(): Invoice
    {
        $suffix = uniqid('', true);
        $companyId = DB::table('companies')->insertGetId([
            'name' => "Writer Company {$suffix}",
        ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => "WRITER-{$suffix}",
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => '1000.00',
            'status' => 'issued',
        ]);

        return Invoice::query()->findOrFail($invoiceId);
    }

    private function line(Invoice $invoice, string $amount, ?string $periodStart = null): int
    {
        return DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoice->getKey(),
            'description' => 'Writer test line',
            'amount' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodStart,
        ]);
    }

    private function payment(
        Invoice $invoice,
        string $amount,
        string $status = 'confirmed',
        string $paymentDate = '2026-07-01',
        ?string $comment = null
    ): int {
        return DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->getKey(),
            'company_id' => $invoice->company_id,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => $comment,
        ]);
    }

    private function assertAllocation(int $paymentId, int $lineId, string $amount): void
    {
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $paymentId,
            'invoice_line_id' => $lineId,
            'amount' => $amount,
        ]);
    }

    /** @return array{created: int, updated: int, deleted: int, unchanged: int} */
    private function noChanges(): array
    {
        return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0];
    }
}
