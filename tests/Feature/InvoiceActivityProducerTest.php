<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Models\CompanyActivityEvent;
use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityEventType;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;

class InvoiceActivityProducerTest extends AuthorizationTestCase
{
    public function test_manual_web_invoice_create_records_one_financial_event_with_actor_and_snapshot(): void
    {
        $company = $this->company('Invoice activity web company');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract, ['price' => '1200.00']);
        $actor = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('invoices.store'), [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-ACT-WEB-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'lines' => [[
                'description' => $order->title,
                'order_id' => $order->id,
                'amount' => '1200.00',
            ]],
        ])->assertRedirect();

        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::InvoiceCreated->value,
            'category' => 'invoices',
            'visibility_scope' => 'financials',
            'subject_type' => 'invoice',
            'metadata->invoice_number' => 'INV-ACT-WEB-001',
            'metadata->contract_number' => $contract->contract_number,
            'metadata->amount_minor' => 120000,
        ]);
    }

    public function test_api_invoice_create_uses_the_same_shared_producer_once(): void
    {
        $company = $this->company('Invoice activity API company');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract);
        $actor = $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(route('api.companies.invoices.store', $company), [
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-ACT-API-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'lines' => [[
                'description' => $order->title,
                'order_id' => $order->id,
                'amount' => '100.00',
            ]],
        ])->assertCreated();

        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::InvoiceCreated->value,
        ]);
    }

    public function test_multi_period_subscription_invoice_creates_one_event_not_one_per_period(): void
    {
        $company = $this->company('Invoice activity subscription company');
        $contract = $this->contract($company);
        $subscription = $this->subjectSubscription($contract, [
            'title' => 'Monthly Support',
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-08-01',
            'amount' => '600.00',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->post(route('invoices.store'), [
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-ACT-MULTI-001',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'lines' => [[
                'description' => $subscription->title,
                'subscription_id' => $subscription->id,
                'amount' => '600.00',
                'period_count' => 3,
                'expected_period_start' => '2026-08-01',
            ]],
        ])->assertRedirect();

        $this->assertSame(3, Invoice::query()->sole()->lines()->count());
        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'event_type' => CompanyActivityEventType::InvoiceCreated->value,
            'metadata->invoice_number' => 'INV-ACT-MULTI-001',
        ]);
    }

    public function test_draft_edit_creates_zero_invoice_activity_events(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-EDIT');
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->put(route('invoices.update', $invoice), [
            ...$this->invoiceUpdatePayload($invoice),
            'comment' => 'Edited draft only',
        ])->assertRedirect();

        $this->assertSame(0, $this->activityCount($invoice->company));
    }

    public function test_issue_records_one_event_only_after_success_and_auto_credit_does_not_duplicate_it(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-ISSUE');
        CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '100.00',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);

        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $this->activityCount($invoice->company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::InvoiceIssued->value,
            'metadata->invoice_number' => $invoice->invoice_number,
        ]);
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => 'credit.applied',
        ]);
    }

    public function test_invalid_second_issue_creates_no_new_event(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-STALE-ISSUE');
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);

        $this->post(route('invoices.issue', $invoice))->assertRedirect();
        $this->post(route('invoices.issue', $invoice))->assertSessionHasErrors('issue');

        $this->assertSame(1, $this->activityCount($invoice->company));
    }

    public function test_cancel_records_one_event_with_financial_snapshot(): void
    {
        $invoice = $this->invoice('issued', 'INV-ACT-CANCEL');
        $this->actingAsPermissions([PermissionName::InvoicesCancel->value]);

        $this->patch(route('invoices.cancel', $invoice))->assertRedirect();

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(1, $this->activityCount($invoice->company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::InvoiceCancelled->value,
            'metadata->invoice_number' => $invoice->invoice_number,
            'metadata->amount_minor' => 10000,
        ]);
    }

    public function test_web_delete_records_one_event_with_snapshot_and_no_invoice_link(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-DELETE');
        $company = $invoice->company;
        $contractNumber = $invoice->contract->contract_number;
        $actor = $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesDelete->value,
        ]);

        $this->delete(route('invoices.destroy', $invoice))->assertRedirect();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(1, $this->activityCount($company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::InvoiceDeleted->value,
            'category' => 'invoices',
            'visibility_scope' => 'financials',
            'subject_type' => 'invoice',
            'subject_id' => $invoice->id,
            'metadata->invoice_number' => $invoice->invoice_number,
            'metadata->status' => 'draft',
            'metadata->contract_number' => $contractNumber,
            'metadata->amount_minor' => 10000,
        ]);

        $this->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('Удалён черновик инвойса '.$invoice->invoice_number)
            ->assertSee($contractNumber.' · 100,00 ₼')
            ->assertDontSee(route('invoices.show', ['invoice' => $invoice->id, 'origin' => 'company', 'tab' => 'activity']), false);
    }

    public function test_api_delete_uses_the_same_single_activity_producer(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-API-DELETE');
        $actor = $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->deleteJson(route('api.invoices.destroy', $invoice))
            ->assertOk()
            ->assertExactJson(['message' => 'Инвойс удалён']);

        $this->assertSame(1, $this->activityCount($invoice->company));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::InvoiceDeleted->value,
        ]);
    }

    public function test_denied_or_invalid_delete_creates_zero_invoice_activity_events(): void
    {
        $denied = $this->invoice('draft', 'INV-ACT-DENIED-DELETE');
        $this->actingAsPermissions();

        $this->delete(route('invoices.destroy', $denied))->assertForbidden();
        $this->assertSame(0, $this->activityCount($denied->company));

        $invalid = $this->invoice('issued', 'INV-ACT-INVALID-DELETE');
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);

        $this->delete(route('invoices.destroy', $invalid))
            ->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('invoices', ['id' => $invalid->id]);
        $this->assertSame(0, $this->activityCount($invalid->company));
    }

    public function test_activity_insert_failure_rolls_back_invoice_delete(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-DELETE-ROLLBACK');
        $this->actingAsPermissions([PermissionName::InvoicesDelete->value]);
        $exception = new RuntimeException('invoice activity delete failed');
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $this->withoutExceptionHandling();
            $this->delete(route('invoices.destroy', $invoice));
            $this->fail('Invoice deletion should have rolled back.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertSame(0, $this->activityCount($invoice->company));
    }

    public function test_denied_create_and_cancel_create_zero_events(): void
    {
        $company = $this->company('Invoice activity denied company');
        $contract = $this->contract($company);
        $this->actingAsPermissions();

        $this->post(route('invoices.store'), $this->invoiceStorePayload($company, $contract))
            ->assertForbidden();
        $this->assertSame(0, $this->activityCount($company));

        $invoice = $this->invoice('issued', 'INV-ACT-DENIED-CANCEL');
        $this->patch(route('invoices.cancel', $invoice))->assertForbidden();
        $this->assertSame(0, $this->activityCount($invoice->company));
    }

    public function test_activity_insert_failure_rolls_back_create_issue_and_cancel(): void
    {
        $exception = new RuntimeException('invoice activity insert failed');
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $company = $this->company('Invoice activity create rollback');
            $contract = $this->contract($company);
            $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
            $this->withoutExceptionHandling();
            $this->post(route('invoices.store'), $this->invoiceStorePayload($company, $contract));
            $this->fail('Invoice creation should have rolled back.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('company_activity_events', 0);

        Event::forget($eventName);
        $invoice = $this->invoice('draft', 'INV-ACT-ISSUE-ROLLBACK');
        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $this->withoutExceptionHandling();
            $this->post(route('invoices.issue', $invoice));
            $this->fail('Invoice issue should have rolled back.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::forget($eventName);
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame(0, $this->activityCount($invoice->company));

        $cancelledCandidate = $this->invoice('issued', 'INV-ACT-CANCEL-ROLLBACK');
        $this->actingAsPermissions([PermissionName::InvoicesCancel->value]);
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $this->withoutExceptionHandling();
            $this->patch(route('invoices.cancel', $cancelledCandidate));
            $this->fail('Invoice cancellation should have rolled back.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame('issued', $cancelledCandidate->fresh()->status);
        $this->assertSame(0, $this->activityCount($cancelledCandidate->company));
    }

    public function test_payment_and_manual_credit_operations_create_no_invoice_events(): void
    {
        $invoice = $this->invoice('issued', 'INV-ACT-NOISE');
        $pending = app(CreatePendingPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-01',
            'amount' => '25.00',
            'payment_method' => 'transfer',
            'comment' => 'Pending payment',
        ]);
        app(ConfirmPayment::class)->execute($pending);

        CreditBalance::query()->create([
            'company_id' => $invoice->company_id,
            'amount' => '10.00',
        ]);
        app(ApplyCreditToInvoice::class)->execute($invoice->fresh());

        $this->assertSame(0, $this->activityCount($invoice->company));
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::InvoiceIssued->value,
        ]);
    }

    public function test_existing_direct_invoice_rows_are_not_backfilled(): void
    {
        $invoice = $this->invoice('draft', 'INV-ACT-LEGACY');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(0, $this->activityCount($invoice->company));
    }

    private function activityCount(mixed $company): int
    {
        return CompanyActivityEvent::query()->where('company_id', $company->id)->count();
    }
}
