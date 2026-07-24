<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\InvoiceController;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\AuthenticatedTestCase as TestCase;

class InvoiceDeletionAndCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_is_physically_deleted_with_its_lines(): void
    {
        $invoice = $this->invoice('draft');
        $lineId = $this->line($invoice);

        $this->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_lines', ['id' => $lineId]);
    }

    public function test_deleting_subscription_draft_does_not_change_next_billing_date(): void
    {
        $invoice = $this->invoice('draft');
        $subscriptionId = $this->subscription('2026-08-01');
        $this->line($invoice, $subscriptionId);

        $this->delete(route('invoices.destroy', $invoice));

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-08-01',
        ]);
    }

    public function test_non_draft_statuses_cannot_be_physically_deleted(): void
    {
        foreach (['issued', 'partially_paid', 'paid', 'cancelled'] as $status) {
            $invoice = $this->invoice($status, 'DELETE-'.$status);

            $this->delete(route('invoices.destroy', $invoice))
                ->assertSessionHasErrors('delete');

            $this->assertDatabaseHas('invoices', [
                'id' => $invoice->id,
                'status' => $status,
            ]);
        }
    }

    public function test_invoice_with_confirmed_payment_cannot_be_deleted(): void
    {
        $invoice = $this->invoice('draft');
        $this->payment($invoice, 'confirmed');

        $this->delete(route('invoices.destroy', $invoice))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id]);
    }

    public function test_issued_without_confirmed_payments_can_be_cancelled_and_keeps_lines(): void
    {
        $invoice = $this->invoice('issued');
        $lineId = $this->line($invoice);

        $this->patch(route('invoices.cancel', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $lineId]);
    }

    public function test_cancelling_issued_rolls_back_only_the_allowed_subscription_schedule(): void
    {
        $invoice = $this->invoice('issued');
        $subscriptionId = $this->subscription('2026-08-01');
        $lineId = $this->line($invoice, $subscriptionId);

        $this->patch(route('invoices.cancel', $invoice));

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('invoice_lines', ['id' => $lineId]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-07-01',
        ]);
    }

    public function test_partially_paid_and_paid_cannot_be_cancelled(): void
    {
        foreach (['partially_paid', 'paid'] as $status) {
            $invoice = $this->invoice($status, 'CANCEL-'.$status);

            $this->patch(route('invoices.cancel', $invoice))
                ->assertSessionHasErrors('cancel');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status]);
        }
    }

    public function test_repeated_cancellation_does_not_roll_back_twice(): void
    {
        $invoice = $this->invoice('issued');
        $subscriptionId = $this->subscription('2026-08-01');
        $this->line($invoice, $subscriptionId);

        $this->patch(route('invoices.cancel', $invoice));
        DB::table('subscriptions')->where('id', $subscriptionId)->update([
            'next_billing_date' => '2026-09-01',
        ]);

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-09-01',
        ]);
    }

    public function test_later_draft_does_not_block_cancellation(): void
    {
        [$invoice, $subscriptionId] = $this->issuedSubscriptionInvoice();
        $later = $this->invoice('draft', 'LATER-DRAFT');
        $this->line($later, $subscriptionId, '2026-08-01', '2026-08-31');

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
    }

    public function test_later_active_invoice_statuses_block_cancellation(): void
    {
        foreach (['issued', 'partially_paid', 'paid'] as $status) {
            [$invoice, $subscriptionId] = $this->issuedSubscriptionInvoice('EARLY-'.$status);
            $later = $this->invoice($status, 'LATER-'.$status);
            $this->line($later, $subscriptionId, '2026-08-01', '2026-08-31');

            $this->patch(route('invoices.cancel', $invoice))
                ->assertSessionHasErrors('cancel');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
            $this->assertDatabaseHas('subscriptions', [
                'id' => $subscriptionId,
                'next_billing_date' => '2026-08-01',
            ]);
        }
    }

    public function test_custom_billing_period_does_not_change_next_billing_date(): void
    {
        $invoice = $this->invoice('issued');
        $subscriptionId = $this->subscription('2026-10-15', 'custom');
        $this->line($invoice, $subscriptionId, null, null);

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-10-15',
        ]);
    }

    public function test_multiple_lines_of_one_subscription_do_not_cause_multiple_rollbacks(): void
    {
        [$invoice, $subscriptionId] = $this->issuedSubscriptionInvoice();
        $this->line($invoice, $subscriptionId, '2026-08-01', '2026-08-31');

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-08-01',
        ]);
    }

    public function test_changed_next_billing_date_is_never_overwritten_blindly(): void
    {
        [$invoice, $subscriptionId] = $this->issuedSubscriptionInvoice();
        DB::table('subscriptions')->where('id', $subscriptionId)->update([
            'next_billing_date' => '2026-09-01',
        ]);

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-09-01',
        ]);
    }

    public function test_confirmed_payment_blocks_cancellation(): void
    {
        $invoice = $this->invoice('issued');
        $this->payment($invoice, 'confirmed');

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
    }

    public function test_locked_reload_detects_concurrently_changed_status_for_delete_and_cancel(): void
    {
        $staleDelete = $this->invoice('draft', 'STALE-DELETE');
        DB::table('invoices')->where('id', $staleDelete->id)->update(['status' => 'issued']);

        try {
            app(InvoiceController::class)->destroy($staleDelete);
            $this->fail('Deletion should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delete', $exception->errors());
        }

        $staleCancel = $this->invoice('issued', 'STALE-CANCEL');
        DB::table('invoices')->where('id', $staleCancel->id)->update(['status' => 'paid']);

        try {
            app(InvoiceController::class)->cancel($staleCancel);
            $this->fail('Cancellation should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancel', $exception->errors());
        }
    }

    private function issuedSubscriptionInvoice(string $suffix = 'CURRENT'): array
    {
        $invoice = $this->invoice('issued', $suffix);
        $subscriptionId = $this->subscription('2026-08-01');
        $this->line($invoice, $subscriptionId);

        return [$invoice, $subscriptionId];
    }

    private function invoice(string $status, ?string $suffix = null): Invoice
    {
        $suffix ??= uniqid();
        $companyId = DB::table('companies')->insertGetId(['name' => 'Company '.$suffix]);
        $contractId = DB::table('contracts')->insertGetId([
            'company_id' => $companyId,
            'contract_number' => 'CONTRACT-'.$suffix,
            'start_date' => '2026-01-01',
        ]);

        return Invoice::create([
            'company_id' => $companyId,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-'.$suffix,
            'issue_date' => '2026-07-01',
            'due_date' => '2026-07-15',
            'total_amount' => 100,
            'status' => $status,
        ]);
    }

    private function subscription(string $nextBillingDate, string $billingPeriod = 'monthly'): int
    {
        $contractId = DB::table('contracts')->value('id');
        $serviceTypeId = DB::table('service_types')->insertGetId([
            'name' => 'Service '.uniqid(),
            'base_price' => 100,
            'type' => 'subscription',
        ]);

        return DB::table('subscriptions')->insertGetId([
            'contract_id' => $contractId,
            'service_type_id' => $serviceTypeId,
            'start_date' => '2026-01-01',
            'next_billing_date' => $nextBillingDate,
            'billing_period' => $billingPeriod,
            'amount' => 100,
        ]);
    }

    private function line(
        Invoice $invoice,
        ?int $subscriptionId = null,
        ?string $periodStart = '2026-07-01',
        ?string $periodEnd = '2026-07-31'
    ): int {
        return $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => $subscriptionId ? 'Subscription' : 'Manual',
            'amount' => 100,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ])->id;
    }

    private function payment(Invoice $invoice, string $status): int
    {
        return DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-01',
            'amount' => 10,
            'payment_method' => 'transfer',
            'status' => $status,
        ]);
    }
}
