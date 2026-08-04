<?php

namespace Tests\Feature;

use App\Actions\Invoices\DeleteInvoice;
use App\Exceptions\Invoices\InvoiceDeletionException;
use App\Http\Controllers\Web\InvoiceController;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\SubscriptionBillingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\FinancialTestCase as TestCase;

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

    public function test_deleting_subscription_draft_restores_next_billing_date(): void
    {
        $invoice = $this->invoice('draft');
        $subscriptionId = $this->subscription('2026-08-01');
        $key = app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscriptionId,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );
        $this->line($invoice, $subscriptionId, occurrenceKey: $key);

        $this->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-07-01',
        ]);
    }

    public function test_every_payment_status_blocks_web_deletion(): void
    {
        foreach (['pending', 'confirmed', 'cancelled'] as $status) {
            $invoice = $this->invoice('draft', 'WEB-DELETE-PAYMENT-'.$status);
            $lineId = $this->line($invoice);
            $paymentId = $this->payment($invoice, $status);

            $this->delete(route('invoices.destroy', $invoice))
                ->assertSessionHasErrors('delete');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
            $this->assertDatabaseHas('invoice_lines', ['id' => $lineId]);
            $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => $status]);
        }
    }

    public function test_allocation_and_credit_dependencies_block_web_deletion_explicitly(): void
    {
        $source = $this->invoice('draft', 'WEB-DELETE-ALLOCATION-SOURCE');
        $this->line($source);
        $target = $this->invoice('draft', 'WEB-DELETE-ALLOCATION-TARGET');
        $targetLineId = $this->line($target);
        $paymentId = $this->payment($source, 'pending');
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $targetLineId,
            'amount' => '10.00',
        ]);

        $this->delete(route('invoices.destroy', $target))
            ->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation->id]);
        $this->assertDatabaseHas('invoices', ['id' => $target->id]);

        $creditInvoice = $this->invoice('draft', 'WEB-DELETE-CREDIT');
        $creditLineId = $this->line($creditInvoice);
        $balance = CreditBalance::query()->create([
            'company_id' => $creditInvoice->company_id,
            'amount' => '10.00',
        ]);
        $entry = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '-10.00',
            'invoice_id' => $creditInvoice->id,
        ]);

        $this->delete(route('invoices.destroy', $creditInvoice))
            ->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('invoices', ['id' => $creditInvoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $creditLineId]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'id' => $entry->id,
            'invoice_id' => $creditInvoice->id,
        ]);
    }

    public function test_later_subscription_occurrence_blocks_web_deletion_without_changes(): void
    {
        $invoice = $this->invoice('draft', 'WEB-DELETE-EARLY');
        $subscriptionId = $this->subscription('2026-09-01');
        $earlyKey = app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscriptionId,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );
        $earlyLineId = $this->line($invoice, $subscriptionId, occurrenceKey: $earlyKey);
        $later = $this->invoice('draft', 'WEB-DELETE-LATER');
        $laterKey = app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscriptionId,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );
        $laterLineId = $this->line(
            $later,
            $subscriptionId,
            '2026-08-01',
            '2026-08-31',
            $laterKey,
        );

        $this->delete(route('invoices.destroy', $invoice))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $earlyLineId, 'billing_occurrence_key' => $earlyKey]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $laterLineId, 'billing_occurrence_key' => $laterKey]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'next_billing_date' => '2026-09-01']);
    }

    public function test_unexpected_web_delete_exception_is_not_mapped_to_business_error_and_rolls_back(): void
    {
        $invoice = $this->invoice('draft', 'WEB-DELETE-ROLLBACK');
        $subscriptionId = $this->subscription('2026-08-01');
        $key = app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscriptionId,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-31'),
        );
        $lineId = $this->line($invoice, $subscriptionId, occurrenceKey: $key);
        Invoice::deleting(fn (): never => throw new RuntimeException('BROKEN WEB INVOICE DELETE'));
        $this->withoutExceptionHandling();

        $thrown = null;
        try {
            $this->delete(route('invoices.destroy', $invoice));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('BROKEN WEB INVOICE DELETE', $thrown->getMessage());
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $lineId, 'billing_occurrence_key' => $key]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'next_billing_date' => '2026-08-01']);
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

    public function test_later_draft_reservation_blocks_cancellation_without_partial_changes(): void
    {
        [$invoice, $subscriptionId, $currentLineId] = $this->issuedSubscriptionInvoice();
        $currentKey = str_repeat('a', 64);
        DB::table('invoice_lines')->where('id', $currentLineId)->update([
            'billing_occurrence_key' => $currentKey,
        ]);
        $later = $this->invoice('draft', 'LATER-DRAFT');
        $laterKey = str_repeat('b', 64);
        $laterLineId = $this->line($later, $subscriptionId, '2026-08-01', '2026-08-31', $laterKey);

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-08-01',
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'id' => $currentLineId,
            'billing_occurrence_key' => $currentKey,
        ]);
        $this->assertDatabaseHas('invoices', ['id' => $later->id, 'status' => 'draft']);
        $this->assertDatabaseHas('invoice_lines', [
            'id' => $laterLineId,
            'billing_occurrence_key' => $laterKey,
        ]);
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

    public function test_custom_billing_period_cancellation_restores_occurrence_start(): void
    {
        $invoice = $this->invoice('issued');
        $subscriptionId = $this->subscription('2026-10-15', 'custom');
        $this->line($invoice, $subscriptionId, '2026-09-01', '2026-10-14');

        $this->patch(route('invoices.cancel', $invoice))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscriptionId,
            'next_billing_date' => '2026-09-01',
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
            app(DeleteInvoice::class)->execute($staleDelete);
            $this->fail('Deletion should have been blocked.');
        } catch (InvoiceDeletionException $exception) {
            $this->assertSame('Удалить можно только черновик инвойса.', $exception->getMessage());
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
        $lineId = $this->line($invoice, $subscriptionId);

        return [$invoice, $subscriptionId, $lineId];
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
            'custom_interval_value' => $billingPeriod === 'custom' ? 45 : null,
            'custom_interval_unit' => $billingPeriod === 'custom' ? 'day' : null,
            'amount' => 100,
        ]);
    }

    private function line(
        Invoice $invoice,
        ?int $subscriptionId = null,
        ?string $periodStart = '2026-07-01',
        ?string $periodEnd = '2026-07-31',
        ?string $occurrenceKey = null,
    ): int {
        return $invoice->lines()->create([
            'subscription_id' => $subscriptionId,
            'description' => $subscriptionId ? 'Subscription' : 'Manual',
            'amount' => 100,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'billing_occurrence_key' => $occurrenceKey,
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
            'cancelled_at' => $status === 'cancelled' ? now() : null,
        ]);
    }
}
