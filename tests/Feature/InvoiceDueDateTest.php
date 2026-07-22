<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\InvoiceDueDateSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceDueDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_uses_ten_calendar_days(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('SUB-10');
        $subscriptionId = $this->subscription($contractId, 10);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->subscriptionLine($subscriptionId)],
            'INV-SUB-10'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-11', Invoice::query()->sole()->due_date);
    }

    public function test_order_uses_thirty_calendar_days(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('ORDER-30');
        $orderId = $this->order($contractId, 30);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId)],
            'INV-ORDER-30'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-31', Invoice::query()->sole()->due_date);
    }

    public function test_order_with_zero_payment_terms_is_due_on_issue_date(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('ORDER-0');
        $orderId = $this->order($contractId, 0);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId)],
            'INV-ORDER-0'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-01', Invoice::query()->sole()->due_date);
    }

    public function test_minimum_payment_terms_are_used_for_multiple_linked_items(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('MINIMUM');
        $orderId = $this->order($contractId, 30);
        $subscriptionId = $this->subscription($contractId, 10);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId), $this->subscriptionLine($subscriptionId)],
            'INV-MINIMUM'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-11', Invoice::query()->sole()->due_date);
    }

    public function test_manual_line_does_not_affect_linked_payment_terms(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('MIXED');
        $orderId = $this->order($contractId, 15);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId), $this->manualLine()],
            'INV-MIXED'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-16', Invoice::query()->sole()->due_date);
    }

    public function test_manual_due_date_is_kept_when_all_lines_are_manual(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('MANUAL');
        $payload = $this->storePayload(
            $companyId,
            $contractId,
            [$this->manualLine()],
            'INV-MANUAL'
        );
        $payload['due_date'] = '2026-09-05';

        $this->post(route('invoices.store'), $payload)
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-09-05', Invoice::query()->sole()->due_date);
    }

    public function test_forged_due_date_is_ignored_for_linked_item(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('FORGED');
        $orderId = $this->order($contractId, 15);
        $payload = $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId)],
            'INV-FORGED'
        );
        $payload['due_date'] = '2030-01-01';

        $this->post(route('invoices.store'), $payload)
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-16', Invoice::query()->sole()->due_date);
    }

    public function test_changing_issue_date_recalculates_draft_due_date(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('UPDATE-DATE');
        $orderId = $this->order($contractId, 15);
        $line = $invoice->lines()->create($this->orderLine($orderId));

        $this->put(route('invoices.update', $invoice), $this->updatePayload(
            $invoice,
            [[
                'id' => $line->id,
                ...$this->orderLine($orderId),
            ]],
            '2026-08-10'
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-25', $invoice->fresh()->due_date);
    }

    public function test_removing_shortest_item_recalculates_using_remaining_item(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('REMOVE-MIN');
        $orderId = $this->order($contractId, 30);
        $subscriptionId = $this->subscription($contractId, 10);
        $orderLine = $invoice->lines()->create($this->orderLine($orderId));
        $invoice->lines()->create($this->subscriptionLine($subscriptionId));

        $this->put(route('invoices.update', $invoice), $this->updatePayload(
            $invoice,
            [[
                'id' => $orderLine->id,
                ...$this->orderLine($orderId),
            ]]
        ))->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
        $this->assertCount(1, $invoice->fresh()->lines);
    }

    public function test_issue_recalculates_due_date_from_current_payment_terms(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('ISSUE');
        $orderId = $this->order($contractId, 15);
        $invoice->lines()->create($this->orderLine($orderId));

        DB::table('orders')->where('id', $orderId)->update([
            'payment_terms' => 20,
        ]);

        $this->post(route('invoices.issue', $invoice))
            ->assertSessionDoesntHaveErrors();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('2026-08-21', $invoice->due_date);
    }

    public function test_changing_order_terms_recalculates_issued_invoice_in_both_directions(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('ISSUED-STABLE');
        $orderId = $this->order($contractId, 30);
        $invoice->lines()->create($this->orderLine($orderId));

        $this->post(route('invoices.issue', $invoice))
            ->assertSessionDoesntHaveErrors();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('2026-08-31', $invoice->due_date);

        $order = Order::findOrFail($orderId);
        $this->put(route('orders.update', $order), $this->orderUpdatePayload(7))
            ->assertSessionDoesntHaveErrors();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('2026-08-08', $invoice->due_date);

        $this->put(route('orders.update', $order), $this->orderUpdatePayload(30))
            ->assertSessionDoesntHaveErrors();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('2026-08-31', $invoice->due_date);
    }

    public function test_updating_order_terms_recalculates_linked_draft_in_both_directions(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('ORDER-SYNC');
        $orderId = $this->order($contractId, 30);
        $invoice->lines()->create($this->orderLine($orderId));
        $order = Order::findOrFail($orderId);

        $this->put(route('orders.update', $order), $this->orderUpdatePayload(7))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-08-08', $invoice->fresh()->due_date);

        $this->put(route('orders.update', $order), $this->orderUpdatePayload(30))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
    }

    public function test_order_update_recalculates_whole_invoice_using_remaining_minimum(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('WHOLE-INVOICE');
        $thirtyDayOrder = $this->order($contractId, 30);
        $sevenDayOrder = $this->order($contractId, 7);
        $invoice->lines()->createMany([
            $this->orderLine($thirtyDayOrder),
            $this->orderLine($sevenDayOrder),
        ]);

        $this->put(
            route('orders.update', Order::findOrFail($sevenDayOrder)),
            $this->orderUpdatePayload(40)
        )->assertSessionDoesntHaveErrors();

        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
    }

    public function test_order_update_changes_all_open_invoices_but_not_paid_cancelled_or_unrelated(): void
    {
        [$firstDraft, $contractId] = $this->draftInvoice('MULTI-FIRST');
        $orderId = $this->order($contractId, 30);
        $firstDraft->lines()->create($this->orderLine($orderId));

        $affected = [$firstDraft];
        foreach (['MULTI-SECOND', 'ISSUED', 'PARTIAL', 'PAID', 'CANCELLED'] as $suffix) {
            [$invoice] = $this->draftInvoiceForContract($contractId, $suffix);
            $invoice->lines()->create($this->orderLine($orderId));
            $affected[] = $invoice;
        }

        $statuses = ['draft', 'draft', 'issued', 'partially_paid', 'paid', 'cancelled'];
        foreach ($affected as $index => $invoice) {
            $invoice->update(['status' => $statuses[$index], 'due_date' => '2026-08-31']);
        }

        [$unrelated, $otherContractId] = $this->draftInvoice('UNRELATED');
        $unrelatedOrder = $this->order($otherContractId, 30);
        $unrelated->lines()->create($this->orderLine($unrelatedOrder));

        $this->put(
            route('orders.update', Order::findOrFail($orderId)),
            $this->orderUpdatePayload(7)
        )->assertSessionDoesntHaveErrors();

        foreach (array_slice($affected, 0, 4) as $index => $invoice) {
            $invoice->refresh();
            $this->assertSame('2026-08-08', $invoice->due_date);
            $this->assertSame($statuses[$index], $invoice->status);
            $this->assertSame('100.00', $invoice->total_amount);
            $this->assertCount(0, $invoice->payments);
            $this->assertCount(0, $invoice->lines->flatMap->allocations);
        }
        foreach (array_slice($affected, 4) as $invoice) {
            $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
        }
        $this->assertSame('2026-08-02', $unrelated->fresh()->due_date);
    }

    public function test_api_order_update_recalculates_linked_draft(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('API-ORDER-SYNC');
        $orderId = $this->order($contractId, 30);
        $invoice->lines()->create($this->orderLine($orderId));

        $this->putJson(route('api.orders.update', $orderId), ['payment_terms' => 7])
            ->assertOk();

        $this->assertSame('2026-08-08', $invoice->fresh()->due_date);
    }

    public function test_subscription_update_recalculates_linked_partially_paid_invoice(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('SUBSCRIPTION-SYNC');
        $subscriptionId = $this->subscription($contractId, 30);
        $invoice->lines()->create($this->subscriptionLine($subscriptionId));
        $invoice->update(['status' => 'partially_paid', 'due_date' => '2026-08-31']);

        $this->putJson(route('api.subscriptions.update', $subscriptionId), ['payment_terms' => 7])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('2026-08-08', $invoice->due_date);
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertSame('100.00', $invoice->total_amount);
    }

    public function test_web_subscription_update_recalculates_linked_issued_invoice(): void
    {
        [$invoice, $contractId] = $this->draftInvoice('WEB-SUBSCRIPTION-SYNC');
        $subscriptionId = $this->subscription($contractId, 30);
        $invoice->lines()->create($this->subscriptionLine($subscriptionId));
        $invoice->update(['status' => 'issued', 'due_date' => '2026-08-31']);

        $subscription = Subscription::findOrFail($subscriptionId);
        $this->put(route('subscriptions.update', $subscription), [
            'title' => 'Subscription',
            'start_date' => '2026-01-01',
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => 7,
            'status' => 'active',
        ])->assertSessionDoesntHaveErrors();

        $invoice->refresh();
        $this->assertSame('2026-08-08', $invoice->due_date);
        $this->assertSame('issued', $invoice->status);
    }

    public function test_synchronization_failure_rolls_back_order_update(): void
    {
        [, $contractId] = $this->companyAndContract('ROLLBACK-SYNC');
        $order = Order::findOrFail($this->order($contractId, 30));

        $this->mock(InvoiceDueDateSynchronizer::class)
            ->shouldReceive('synchronizeForOrder')
            ->once()
            ->andThrow(new \RuntimeException('Broken linked invoice'));

        try {
            $this->put(route('orders.update', $order), $this->orderUpdatePayload(7));
            $this->fail('The synchronization exception should escape the request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Broken linked invoice', $exception->getMessage());
        }

        $this->assertSame(30, (int) $order->fresh()->payment_terms);
    }

    public function test_payment_terms_outside_allowed_range_are_rejected(): void
    {
        [$companyId, $contractId] = $this->companyAndContract('INVALID');
        $orderId = $this->order($contractId, 3651);

        $this->post(route('invoices.store'), $this->storePayload(
            $companyId,
            $contractId,
            [$this->orderLine($orderId)],
            'INV-INVALID'
        ))->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rollback_compatibility_accepts_null_and_signed_tinyint_values(): void
    {
        $migration = $this->paymentTermsMigration();

        $this->assertTrue($migration::isRollbackCompatible(null));
        $this->assertTrue($migration::isRollbackCompatible(1));
        $this->assertTrue($migration::isRollbackCompatible(127));
        $this->assertTrue($migration::isRollbackCompatible(-128));
        $this->assertFalse($migration::isRollbackCompatible(128));
        $this->assertFalse($migration::isRollbackCompatible(365));
    }

    public function test_rollback_is_blocked_without_changing_incompatible_values(): void
    {
        [, $contractId] = $this->companyAndContract('ROLLBACK');
        $orderId = $this->order($contractId, 128);
        $subscriptionId = $this->subscription($contractId, 365);
        $migration = $this->paymentTermsMigration();

        try {
            $migration->down();
            $this->fail('Rollback should be blocked for incompatible payment terms.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('orders: 1 incompatible row(s)', $exception->getMessage());
            $this->assertStringContainsString('subscriptions: 1 incompatible row(s)', $exception->getMessage());
            $this->assertStringContainsString('stopped to prevent data loss', $exception->getMessage());
            $this->assertStringContainsString('signed TINYINT range -128..127', $exception->getMessage());
        }

        $this->assertSame(128, (int) DB::table('orders')->where('id', $orderId)->value('payment_terms'));
        $this->assertSame(365, (int) DB::table('subscriptions')->where('id', $subscriptionId)->value('payment_terms'));
    }

    private function companyAndContract(string $suffix): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Company '.$suffix,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        return [$companyId, $contractId];
    }

    private function draftInvoice(string $suffix): array
    {
        [$companyId, $contractId] = $this->companyAndContract($suffix);
        $invoice = Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$suffix,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-02',
            'total_amount' => 100,
            'status' => 'draft',
        ]);

        return [$invoice, $contractId];
    }

    private function draftInvoiceForContract(int $contractId, string $suffix): array
    {
        $invoice = Invoice::create([
            'company_id' => DB::table('contracts')->where('id', $contractId)->value('company_id'),
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$suffix,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-02',
            'total_amount' => 100,
            'status' => 'draft',
        ]);

        return [$invoice, $contractId];
    }

    private function serviceType(string $type): int
    {
        return DB::table('service_types')->insertGetId([
            'name' => ucfirst($type).' '.uniqid(),
            'base_price' => 100,
            'type' => $type,
        ]);
    }

    private function order(int $contractId, int $paymentTerms): int
    {
        return DB::table('orders')->insertGetId([
            'contract_id' => $contractId,
            'service_type_id' => $this->serviceType('one_time'),
            'title' => 'One-time service',
            'order_date' => '2026-08-01',
            'price' => 100,
            'payment_terms' => $paymentTerms,
            'status' => 'in_progress',
        ]);
    }

    private function subscription(int $contractId, int $paymentTerms): int
    {
        return DB::table('subscriptions')->insertGetId([
            'contract_id' => $contractId,
            'service_type_id' => $this->serviceType('subscription'),
            'title' => 'Subscription',
            'start_date' => '2026-01-01',
            'next_billing_date' => '2026-08-01',
            'billing_period' => 'monthly',
            'amount' => 100,
            'payment_terms' => $paymentTerms,
            'status' => 'active',
        ]);
    }

    private function storePayload(
        int $companyId,
        int $contractId,
        array $lines,
        string $number
    ): array {
        return [
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-12-31',
            'lines' => $lines,
        ];
    }

    private function updatePayload(
        Invoice $invoice,
        array $lines,
        string $issueDate = '2026-08-01'
    ): array {
        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => $issueDate,
            'due_date' => '2030-01-01',
            'lines' => $lines,
        ];
    }

    private function orderLine(int $orderId): array
    {
        return [
            'description' => 'One-time service',
            'amount' => 100,
            'subscription_id' => null,
            'order_id' => $orderId,
            'period_start' => null,
            'period_end' => null,
        ];
    }

    private function orderUpdatePayload(int $paymentTerms): array
    {
        return [
            'title' => 'One-time service',
            'order_date' => '2026-08-01',
            'price' => 100,
            'payment_terms' => $paymentTerms,
            'status' => 'in_progress',
        ];
    }

    private function subscriptionLine(int $subscriptionId): array
    {
        return [
            'description' => 'Subscription',
            'amount' => 100,
            'subscription_id' => $subscriptionId,
            'order_id' => null,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ];
    }

    private function manualLine(): array
    {
        return [
            'description' => 'Manual line',
            'amount' => 50,
        ];
    }

    private function paymentTermsMigration(): object
    {
        return require database_path(
            'migrations/2026_07_21_130000_expand_payment_terms_range.php'
        );
    }
}
