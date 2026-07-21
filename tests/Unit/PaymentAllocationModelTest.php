<?php

namespace Tests\Unit;

use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentAllocationModelTest extends TestCase
{
    public function test_amount_is_cast_to_two_decimal_places(): void
    {
        $allocation = new PaymentAllocation(['amount' => '12.5']);

        $this->assertSame('12.50', $allocation->amount);
    }

    public function test_allocation_fields_are_mass_assignable_in_memory(): void
    {
        $allocation = new PaymentAllocation([
            'payment_id' => 10,
            'invoice_line_id' => 20,
            'amount' => '30.00',
        ]);

        $this->assertSame(10, $allocation->payment_id);
        $this->assertSame(20, $allocation->invoice_line_id);
        $this->assertSame('30.00', $allocation->amount);
    }

    public function test_payment_relation_uses_expected_model_and_foreign_key(): void
    {
        $relation = (new PaymentAllocation())->payment();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(Payment::class, $relation->getRelated());
        $this->assertSame('payment_id', $relation->getForeignKeyName());
    }

    public function test_invoice_line_relation_uses_expected_model_and_foreign_key(): void
    {
        $relation = (new PaymentAllocation())->invoiceLine();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(InvoiceLine::class, $relation->getRelated());
        $this->assertSame('invoice_line_id', $relation->getForeignKeyName());
    }

    public function test_payment_allocations_relation_uses_expected_model_and_foreign_key(): void
    {
        $relation = (new Payment())->allocations();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(PaymentAllocation::class, $relation->getRelated());
        $this->assertSame('payment_id', $relation->getForeignKeyName());
    }

    public function test_invoice_line_allocations_relation_uses_expected_model_and_foreign_key(): void
    {
        $relation = (new InvoiceLine())->allocations();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(PaymentAllocation::class, $relation->getRelated());
        $this->assertSame('invoice_line_id', $relation->getForeignKeyName());
    }

    public function test_relation_metadata_can_be_inspected_without_executing_sql(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        (new PaymentAllocation())->payment();
        (new PaymentAllocation())->invoiceLine();
        (new Payment())->allocations();
        (new InvoiceLine())->allocations();

        $this->assertSame([], $queries);
    }
}
