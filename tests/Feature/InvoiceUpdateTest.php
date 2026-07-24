<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_subscription_line_keeps_link_and_period(): void
    {
        [$invoice, $contractId] = $this->invoice();
        $subscriptionId = $this->subscription($contractId);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => 'Subscription',
            'amount' => 100,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => $line->id,
            'description' => 'Updated subscription',
            'amount' => 125,
            'subscription_id' => $subscriptionId,
            'order_id' => null,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]]))->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('invoice_lines', [
            'id' => $line->id,
            'subscription_id' => $subscriptionId,
            'order_id' => null,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'description' => 'Updated subscription',
        ]);
    }

    public function test_draft_order_line_keeps_order_link(): void
    {
        [$invoice, $contractId] = $this->invoice();
        $orderId = $this->order($contractId);
        $line = $invoice->lines()->create([
            'order_id' => $orderId,
            'description' => 'One-time service',
            'amount' => 80,
        ]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => $line->id,
            'description' => 'Updated service',
            'amount' => 90,
            'subscription_id' => null,
            'order_id' => $orderId,
            'period_start' => null,
            'period_end' => null,
        ]]))->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('invoice_lines', [
            'id' => $line->id,
            'subscription_id' => null,
            'order_id' => $orderId,
            'description' => 'Updated service',
        ]);
    }

    public function test_manual_line_stays_manual(): void
    {
        [$invoice] = $this->invoice();
        $line = $invoice->lines()->create([
            'description' => 'Manual',
            'amount' => 30,
        ]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => $line->id,
            'description' => 'Updated manual',
            'amount' => 35,
            'subscription_id' => null,
            'order_id' => null,
            'period_start' => null,
            'period_end' => null,
        ]]))->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('invoice_lines', [
            'id' => $line->id,
            'subscription_id' => null,
            'order_id' => null,
            'description' => 'Updated manual',
        ]);
    }

    public function test_link_cannot_be_replaced_with_another_contract_link(): void
    {
        [$invoice, $contractId] = $this->invoice();
        [, $otherContractId] = $this->invoice('OTHER');
        $subscriptionId = $this->subscription($contractId);
        $otherSubscriptionId = $this->subscription($otherContractId);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => 'Subscription',
            'amount' => 100,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $response = $this->from(route('invoices.edit', $invoice))->put(
            route('invoices.update', $invoice),
            $this->payload($invoice, [[
                'id' => $line->id,
                'description' => 'Tampered',
                'amount' => 100,
                'subscription_id' => $otherSubscriptionId,
                'order_id' => null,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
            ]])
        );

        $response->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('lines.0.id');
        $this->assertDatabaseHas('invoice_lines', [
            'id' => $line->id,
            'subscription_id' => $subscriptionId,
            'description' => 'Subscription',
        ]);
    }

    public function test_order_cannot_be_replaced_with_another_contract_order(): void
    {
        [$invoice, $contractId] = $this->invoice();
        [, $otherContractId] = $this->invoice('OTHER-ORDER');
        $orderId = $this->order($contractId);
        $otherOrderId = $this->order($otherContractId);
        $line = $invoice->lines()->create([
            'order_id' => $orderId,
            'description' => 'Order',
            'amount' => 80,
        ]);

        $response = $this->from(route('invoices.edit', $invoice))->put(
            route('invoices.update', $invoice),
            $this->payload($invoice, [[
                'id' => $line->id,
                'description' => 'Tampered',
                'amount' => 80,
                'subscription_id' => null,
                'order_id' => $otherOrderId,
                'period_start' => null,
                'period_end' => null,
            ]])
        );

        $response->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('lines.0.id');
        $this->assertDatabaseHas('invoice_lines', [
            'id' => $line->id,
            'order_id' => $orderId,
            'description' => 'Order',
        ]);
    }

    public function test_paid_invoice_lines_cannot_be_changed(): void
    {
        foreach (['paid'] as $status) {
            [$invoice] = $this->invoice(strtoupper($status), $status);
            $line = $invoice->lines()->create([
                'description' => 'Locked',
                'amount' => 20,
            ]);

            $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
                'id' => $line->id,
                'description' => 'Changed',
                'amount' => 25,
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]]))->assertRedirect(route('invoices.show', $invoice));

            $this->assertDatabaseHas('invoice_lines', [
                'id' => $line->id,
                'description' => 'Locked',
                'amount' => 20,
            ]);
        }
    }

    public function test_issued_linked_line_cannot_be_removed_and_transaction_rolls_back(): void
    {
        [$invoice, $contractId] = $this->invoice('ISSUED-LINK', 'issued');
        $subscriptionId = $this->subscription($contractId);
        $linked = $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => 'Linked subscription',
            'amount' => 100,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
        $manual = $invoice->lines()->create(['description' => 'Manual', 'amount' => 20]);

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->payload($invoice, [[
                'id' => $manual->id,
                'description' => 'Changed before failure',
                'amount' => 30,
                'subscription_id' => null,
                'order_id' => null,
                'period_start' => null,
                'period_end' => null,
            ]]))
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('lines');

        $this->assertSame('Linked subscription', $linked->fresh()->description);
        $this->assertSame('Manual', $manual->fresh()->description);
        $this->assertSame('100.00', $invoice->fresh()->total_amount);
    }

    public function test_issued_linked_line_can_change_user_fields_and_manual_lines_can_be_replaced(): void
    {
        [$invoice, $contractId] = $this->invoice('ISSUED-EDIT', 'issued');
        $orderId = $this->order($contractId);
        $linked = $invoice->lines()->create([
            'order_id' => $orderId,
            'description' => 'Linked order',
            'amount' => 80,
        ]);
        $manual = $invoice->lines()->create(['description' => 'Old manual', 'amount' => 20]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => $linked->id,
            'description' => 'Updated linked order',
            'amount' => 90,
            'subscription_id' => null,
            'order_id' => $orderId,
            'period_start' => null,
            'period_end' => null,
        ], [
            'id' => null,
            'description' => 'New manual',
            'amount' => 15,
            'subscription_id' => null,
            'order_id' => null,
            'period_start' => null,
            'period_end' => null,
        ]]))->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseMissing('invoice_lines', ['id' => $manual->id]);
        $this->assertDatabaseHas('invoice_lines', [
            'id' => $linked->id,
            'order_id' => $orderId,
            'description' => 'Updated linked order',
            'amount' => 90,
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'description' => 'New manual',
            'subscription_id' => null,
            'order_id' => null,
        ]);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_draft_can_remove_linked_and_manual_lines_and_add_manual_line(): void
    {
        [$invoice, $contractId] = $this->invoice('DRAFT-DELETE', 'draft');
        $subscription = $invoice->lines()->create([
            'subscription_id' => $this->subscription($contractId),
            'description' => 'Subscription to remove',
            'amount' => 40,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
        $order = $invoice->lines()->create([
            'order_id' => $this->order($contractId),
            'description' => 'Order to remove',
            'amount' => 40,
        ]);
        $manual = $invoice->lines()->create(['description' => 'Manual to remove', 'amount' => 20]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => null,
            'description' => 'New manual',
            'amount' => 55,
            'subscription_id' => null,
            'order_id' => null,
            'period_start' => null,
            'period_end' => null,
        ]]))->assertRedirect(route('invoices.show', $invoice));

        foreach ([$subscription, $order, $manual] as $removedLine) {
            $this->assertDatabaseMissing('invoice_lines', ['id' => $removedLine->id]);
        }
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'description' => 'New manual',
            'amount' => 55,
        ]);
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_issued_subscription_period_cannot_be_changed(): void
    {
        [$invoice, $contractId] = $this->invoice('ISSUED-PERIOD', 'issued');
        $subscriptionId = $this->subscription($contractId);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => 'Subscription',
            'amount' => 100,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), $this->payload($invoice, [[
                'id' => $line->id,
                'description' => 'Tampered',
                'amount' => 125,
                'subscription_id' => $subscriptionId,
                'order_id' => null,
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
            ]]))
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('lines.0.id');

        $line->refresh();
        $this->assertSame('2026-07-01', $line->period_start->toDateString());
        $this->assertSame('2026-07-31', $line->period_end->toDateString());
        $this->assertSame('Subscription', $line->description);
    }

    public function test_issued_subscription_edit_does_not_advance_billing_date_or_run_issue_side_effects(): void
    {
        [$invoice, $contractId] = $this->invoice('ISSUED-SUBSCRIPTION', 'issued');
        $subscriptionId = $this->subscription($contractId);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => 'Subscription',
            'amount' => 100,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        $this->put(route('invoices.update', $invoice), $this->payload($invoice, [[
            'id' => $line->id,
            'description' => 'Updated subscription',
            'amount' => 125,
            'subscription_id' => $subscriptionId,
            'order_id' => null,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]]))->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(
            '2026-07-01',
            DB::table('subscriptions')->where('id', $subscriptionId)->value('next_billing_date')
        );
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    private function invoice(string $suffix = 'MAIN', string $status = 'draft'): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => "Company {$suffix}",
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => "CONTRACT-{$suffix}",
            'start_date' => '2026-01-01',
        ]);
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => "INV-{$suffix}",
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'total_amount' => 100,
            'status' => $status,
        ]);

        return [$invoice, $contractId];
    }

    private function subscription(int $contractId): int
    {
        return DB::table('subscriptions')->insertGetId([
            'contract_id' => $contractId,
            'title' => 'Subscription',
            'start_date' => '2026-01-01',
            'next_billing_date' => '2026-07-01',
            'billing_period' => 'monthly',
            'amount' => 100,
        ]);
    }

    private function order(int $contractId): int
    {
        return DB::table('orders')->insertGetId([
            'contract_id' => $contractId,
            'title' => 'One-time service',
            'order_date' => '2026-07-01',
            'price' => 80,
            'payment_terms' => 14,
        ]);
    }

    private function payload(Invoice $invoice, array $lines): array
    {
        return [
            'company_id' => $invoice->company_id,
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'status' => 'draft',
            'lines' => $lines,
        ];
    }
}
