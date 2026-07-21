<?php

namespace Tests\Feature;

use App\Models\PaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentAllocationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_allocations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('payment_allocations'));
        $this->assertTrue(Schema::hasColumns('payment_allocations', [
            'id',
            'payment_id',
            'invoice_line_id',
            'amount',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_valid_allocation_can_be_created(): void
    {
        [$paymentId, $invoiceLineId] = $this->paymentAndLine();

        $allocation = PaymentAllocation::create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '25.50',
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'id' => $allocation->id,
            'payment_id' => $paymentId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '25.50',
        ]);
    }

    public function test_payment_and_invoice_line_pair_must_be_unique(): void
    {
        [$paymentId, $invoiceLineId] = $this->paymentAndLine();

        PaymentAllocation::create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '10.00',
        ]);

        $this->expectException(QueryException::class);

        PaymentAllocation::create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '5.00',
        ]);
    }

    public function test_missing_payment_is_rejected_by_foreign_key(): void
    {
        [, $invoiceLineId] = $this->paymentAndLine();

        $this->expectException(QueryException::class);

        PaymentAllocation::create([
            'payment_id' => 999999,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '10.00',
        ]);
    }

    public function test_missing_invoice_line_is_rejected_by_foreign_key(): void
    {
        [$paymentId] = $this->paymentAndLine();

        $this->expectException(QueryException::class);

        PaymentAllocation::create([
            'payment_id' => $paymentId,
            'invoice_line_id' => 999999,
            'amount' => '10.00',
        ]);
    }

    public function test_payment_delete_is_restricted_while_allocation_exists(): void
    {
        [$paymentId, $invoiceLineId] = $this->paymentAndLine();
        $this->allocation($paymentId, $invoiceLineId);

        $this->expectException(QueryException::class);

        DB::table('payments')->where('id', $paymentId)->delete();
    }

    public function test_invoice_line_delete_is_restricted_while_allocation_exists(): void
    {
        [$paymentId, $invoiceLineId] = $this->paymentAndLine();
        $this->allocation($paymentId, $invoiceLineId);

        $this->expectException(QueryException::class);

        DB::table('invoice_lines')->where('id', $invoiceLineId)->delete();
    }

    private function allocation(int $paymentId, int $invoiceLineId): void
    {
        PaymentAllocation::create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $invoiceLineId,
            'amount' => '10.00',
        ]);
    }

    /** @return array{int, int} */
    private function paymentAndLine(): array
    {
        $suffix = uniqid();
        $companyId = DB::table('companies')->insertGetId([
            'name' => "Allocation Company {$suffix}",
        ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => "ALLOC-{$suffix}",
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
        $invoiceLineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'description' => 'Allocated line',
            'amount' => '100.00',
        ]);
        $paymentId = DB::table('payments')->insertGetId([
            'invoice_id' => $invoiceId,
            'company_id' => $companyId,
            'payment_date' => '2026-07-21',
            'amount' => '50.00',
            'payment_method' => 'transfer',
            'status' => 'confirmed',
        ]);

        return [$paymentId, $invoiceLineId];
    }
}
