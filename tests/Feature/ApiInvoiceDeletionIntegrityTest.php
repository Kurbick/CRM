<?php

namespace Tests\Feature;

use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionBillingSchedule;
use App\Support\Access\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiInvoiceDeletionIntegrityTest extends AuthorizationTestCase
{
    public function test_manual_and_order_backed_drafts_are_deleted_without_parent_or_source_mutation(): void
    {
        $manual = $this->draft('API-DELETE-MANUAL');
        $manualLine = $manual->lines()->create(['description' => 'Manual line', 'amount' => '100.00']);
        $orderInvoice = $this->draft('API-DELETE-ORDER');
        $order = $this->subjectOrder($orderInvoice->contract);
        $orderLine = $orderInvoice->lines()->create([
            'order_id' => $order->id,
            'description' => 'Order line',
            'amount' => '100.00',
        ]);
        $companyState = $manual->company->getAttributes();
        $contractState = $manual->contract->getAttributes();
        $orderState = $order->fresh()->getAttributes();
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $manual))
            ->assertOk()
            ->assertExactJson(['message' => 'Инвойс удалён']);
        $this->deleteJson(route('api.invoices.destroy', $orderInvoice))
            ->assertOk()
            ->assertExactJson(['message' => 'Инвойс удалён']);

        $this->assertDatabaseMissing('invoices', ['id' => $manual->id]);
        $this->assertDatabaseMissing('invoice_lines', ['id' => $manualLine->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $orderInvoice->id]);
        $this->assertDatabaseMissing('invoice_lines', ['id' => $orderLine->id]);
        $this->assertSame($companyState, $manual->company->fresh()->getAttributes());
        $this->assertSame($contractState, $manual->contract->fresh()->getAttributes());
        $this->assertSame($orderState, $order->fresh()->getAttributes());
    }

    public function test_non_draft_states_are_blocked_for_regular_and_administrator_users(): void
    {
        foreach (['issued', 'partially_paid', 'paid', 'cancelled'] as $status) {
            $invoice = $this->draft('API-DELETE-STATE-'.$status, $status);
            $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);
            $this->deleteJson(route('api.invoices.destroy', $invoice))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('invoice');
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status]);
        }

        $invoice = $this->draft('API-DELETE-STATE-ADMIN', 'issued');
        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');
        $this->deleteJson(route('api.invoices.destroy', $invoice))->assertUnprocessable();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_every_payment_status_blocks_deletion_without_mutation(): void
    {
        foreach (['pending', 'confirmed', 'cancelled'] as $status) {
            $invoice = $this->draft('API-DELETE-PAYMENT-'.$status);
            $line = $invoice->lines()->create(['description' => 'Payment line', 'amount' => '100.00']);
            $paymentId = $this->insertPayment($invoice, $status);
            $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

            $this->deleteJson(route('api.invoices.destroy', $invoice))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('invoice');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
            $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
            $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => $status]);
        }
    }

    public function test_cross_invoice_allocation_is_an_explicit_business_conflict(): void
    {
        $source = $this->draft('API-DELETE-ALLOCATION-SOURCE');
        $source->lines()->create(['description' => 'Source line', 'amount' => '100.00']);
        $target = $this->draft('API-DELETE-ALLOCATION-TARGET');
        $targetLine = $target->lines()->create(['description' => 'Target line', 'amount' => '100.00']);
        $paymentId = $this->insertPayment($source, 'pending');
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $paymentId,
            'invoice_line_id' => $targetLine->id,
            'amount' => '25.00',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $target))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertDatabaseHas('invoices', ['id' => $source->id]);
        $this->assertDatabaseHas('invoices', ['id' => $target->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $targetLine->id]);
        $this->assertDatabaseHas('payments', ['id' => $paymentId]);
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation->id]);
    }

    public function test_credit_balance_entry_blocks_deletion_and_retains_invoice_link(): void
    {
        $invoice = $this->draft('API-DELETE-CREDIT');
        $subscription = $this->subjectSubscription($invoice->contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
        ]);
        $line = $this->subscriptionLine($invoice, $subscription, '2026-08-01', '2026-08-31');
        $balance = CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '10.00',
        ]);
        $entry = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '-10.00',
            'invoice_id' => $invoice->id,
            'description' => 'DELETE-CREDIT-MARKER',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $invoice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'id' => $entry->id,
            'invoice_id' => $invoice->id,
        ]);
        $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_subscription_occurrence_is_released_and_can_be_created_again(): void
    {
        $company = $this->company('API-DELETE-SUBSCRIPTION Company');
        $contract = $this->contract($company);
        $subscription = $this->subjectSubscription($contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
        ]);
        $this->actingAsPermissions([
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesDelete->value,
        ]);

        $this->postJson(route('api.companies.invoices.store', $company), [
            'contract_id' => $contract->id,
            'invoice_number' => 'API-DELETE-SUBSCRIPTION-FIRST',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'lines' => [[
                'subscription_id' => $subscription->id,
                'description' => 'Subscription occurrence',
                'amount' => '100.00',
            ]],
        ])->assertCreated();
        $invoice = Invoice::query()->where('invoice_number', 'API-DELETE-SUBSCRIPTION-FIRST')->sole();
        $line = $invoice->lines()->sole();
        $occurrenceKey = $line->billing_occurrence_key;

        $this->deleteJson(route('api.invoices.destroy', $invoice))->assertOk();

        $this->assertDatabaseMissing('invoice_lines', ['id' => $line->id]);
        $this->assertSame('2026-08-01', $subscription->fresh()->next_billing_date->toDateString());

        $this->postJson(route('api.companies.invoices.store', $company), [
            'contract_id' => $contract->id,
            'invoice_number' => 'API-DELETE-SUBSCRIPTION-REPLACEMENT',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'lines' => [[
                'subscription_id' => $subscription->id,
                'description' => 'Replacement occurrence',
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $this->assertSame(
            $occurrenceKey,
            Invoice::query()->where('invoice_number', 'API-DELETE-SUBSCRIPTION-REPLACEMENT')
                ->sole()->lines()->sole()->billing_occurrence_key,
        );
    }

    public function test_later_occurrence_blocks_deletion_without_schedule_or_key_changes(): void
    {
        $invoice = $this->draft('API-DELETE-LATER-EARLY');
        $subscription = $this->subjectSubscription($invoice->contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-10-01',
        ]);
        $early = $this->subscriptionLine($invoice, $subscription, '2026-08-01', '2026-08-31');
        $laterInvoice = $this->draftForContract($invoice->contract, 'API-DELETE-LATER-LATE');
        $later = $this->subscriptionLine($laterInvoice, $subscription, '2026-09-01', '2026-09-30');
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $invoice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoices', ['id' => $laterInvoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $early->id, 'billing_occurrence_key' => $early->billing_occurrence_key]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $later->id, 'billing_occurrence_key' => $later->billing_occurrence_key]);
        $this->assertSame('2026-10-01', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_inconsistent_subscription_metadata_blocks_deletion(): void
    {
        $invoice = $this->draft('API-DELETE-INCONSISTENT');
        $subscription = $this->subjectSubscription($invoice->contract, [
            'next_billing_date' => '2026-09-01',
        ]);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Incomplete occurrence',
            'amount' => '100.00',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'billing_occurrence_key' => null,
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $invoice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id, 'billing_occurrence_key' => null]);
        $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
    }

    public function test_multiple_subscriptions_restore_in_one_bounded_request(): void
    {
        $singleInvoice = $this->draft('API-DELETE-SINGLE-QUERY');
        $singleSubscription = $this->subjectSubscription($singleInvoice->contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
        ]);
        $this->subscriptionLine($singleInvoice, $singleSubscription, '2026-08-01', '2026-08-31');
        $invoice = $this->draft('API-DELETE-MULTIPLE');
        $subscriptions = collect(range(1, 3))->map(function (int $index) use ($invoice): Subscription {
            $subscription = $this->subjectSubscription($invoice->contract, [
                'title' => 'Delete subscription '.$index,
                'start_date' => '2026-08-01',
                'next_billing_date' => $index === 1 ? '2026-10-01' : '2026-09-01',
            ]);
            $this->subscriptionLine($invoice, $subscription, '2026-08-01', '2026-08-31');
            if ($index === 1) {
                $this->subscriptionLine($invoice, $subscription, '2026-09-01', '2026-09-30');
            }

            return $subscription;
        });
        $order = $this->subjectOrder($invoice->contract);
        $invoice->lines()->create([
            'order_id' => $order->id,
            'description' => 'Mixed order line',
            'amount' => '10.00',
        ]);
        $invoice->lines()->create(['description' => 'Mixed manual line', 'amount' => '10.00']);
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $singleCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->deleteJson(route('api.invoices.destroy', $singleInvoice)),
        );
        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->deleteJson(route('api.invoices.destroy', $invoice)),
        );

        $singleCapture['result']->assertOk();
        $capture['result']->assertOk();
        $this->assertLessThanOrEqual(12, DomainQueryRecorder::count($capture['records']));
        $this->assertSame(
            DomainQueryRecorder::count($singleCapture['records']),
            DomainQueryRecorder::count($capture['records']),
        );
        foreach ($subscriptions as $subscription) {
            $this->assertSame('2026-08-01', $subscription->fresh()->next_billing_date->toDateString());
        }
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_unexpected_delete_exception_rolls_back_schedule_and_is_not_masked(): void
    {
        $invoice = $this->draft('API-DELETE-ROLLBACK');
        $subscription = $this->subjectSubscription($invoice->contract, [
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
        ]);
        $line = $this->subscriptionLine($invoice, $subscription, '2026-08-01', '2026-08-31');
        $originalKey = $line->billing_occurrence_key;
        Invoice::deleting(fn (): never => throw new RuntimeException('BROKEN INVOICE DELETE'));
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);
        $this->withoutExceptionHandling();

        $thrown = null;
        try {
            $this->deleteJson(route('api.invoices.destroy', $invoice));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('BROKEN INVOICE DELETE', $thrown->getMessage());
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id, 'billing_occurrence_key' => $originalKey]);
        $this->assertSame('2026-09-01', $subscription->fresh()->next_billing_date->toDateString());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_policy_denial_queries_only_bound_invoice(): void
    {
        $invoice = $this->draft('API-DELETE-QUERY-DENIAL');
        $invoice->lines()->create(['description' => 'Protected line', 'amount' => '100.00']);
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);
        Gate::before(
            fn ($user, string $ability): ?bool => $ability === 'delete' ? false : null,
        );

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->deleteJson(route('api.invoices.destroy', $invoice)),
        );

        $capture['result']->assertForbidden();
        $this->assertSame(['invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    private function draft(string $number, string $status = 'draft'): Invoice
    {
        $company = $this->company($number.' Company');
        $contract = $this->contract($company);

        return $this->draftForContract($contract, $number, $status);
    }

    private function draftForContract($contract, string $number, string $status = 'draft'): Invoice
    {
        return Invoice::query()->create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => $status,
        ]);
    }

    private function insertPayment(Invoice $invoice, string $status): int
    {
        return DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-01',
            'amount' => '25.00',
            'payment_method' => 'transfer',
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function subscriptionLine(
        Invoice $invoice,
        Subscription $subscription,
        string $periodStart,
        string $periodEnd,
    ) {
        $key = app(SubscriptionBillingSchedule::class)->occurrenceKey(
            $subscription->id,
            CarbonImmutable::parse($periodStart),
            CarbonImmutable::parse($periodEnd),
        );

        return $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Subscription occurrence',
            'amount' => '100.00',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'billing_occurrence_key' => $key,
        ]);
    }
}
