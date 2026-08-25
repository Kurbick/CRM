<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CancelPayment;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\CreateConfirmedPayment;
use App\Actions\Payments\CreatePendingPayment;
use App\Models\CompanyActivityEvent;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityEventType;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;

class PaymentCreditActivityProducerTest extends AuthorizationTestCase
{
    public function test_pending_creation_confirmation_and_cancellation_each_record_one_event(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-LIFECYCLE');

        $pending = app(CreatePendingPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-01',
            'amount' => '25.00',
            'payment_method' => 'transfer',
        ]);
        app(ConfirmPayment::class)->execute($pending);
        app(CancelPayment::class)->execute($pending->fresh(), 'Отмена платежа для проверки');

        $this->assertSame([
            CompanyActivityEventType::PaymentPendingCreated->value,
            CompanyActivityEventType::PaymentConfirmed->value,
            CompanyActivityEventType::PaymentCancelled->value,
        ], $this->eventTypes($invoice));
        $this->assertSame(3, $this->activityCount($invoice));
    }

    public function test_pending_payment_cancellation_records_only_payment_cancelled(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-PENDING-CANCEL');
        $pending = app(CreatePendingPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-01',
            'amount' => '25.00',
            'payment_method' => 'cash',
        ]);

        app(CancelPayment::class)->execute($pending, 'Ожидающий платёж зарегистрирован ошибочно');

        $this->assertSame([
            CompanyActivityEventType::PaymentPendingCreated->value,
            CompanyActivityEventType::PaymentCancelled->value,
        ], $this->eventTypes($invoice));
    }

    public function test_direct_confirmed_creation_records_confirmed_only_and_overpayment_is_not_credit_activity(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-CONFIRMED');

        $payment = app(CreateConfirmedPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-01',
            'amount' => '130.00',
            'payment_method' => 'cash',
        ]);

        $this->assertSame('confirmed', $payment->status);
        $this->assertSame([
            CompanyActivityEventType::PaymentConfirmed->value,
        ], $this->eventTypes($invoice));
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::CreditApplied->value,
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'amount' => '30.00',
        ]);
    }

    public function test_manual_credit_applications_record_one_event_per_real_application(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-MANUAL-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $action = app(ApplyCreditToInvoice::class);

        $action->executeManual($invoice, 2000, 5000, 10000);
        $action->executeManual($invoice->fresh(), 3000, 3000, 8000);

        $this->assertSame([
            CompanyActivityEventType::CreditApplied->value,
            CompanyActivityEventType::CreditApplied->value,
        ], $this->eventTypes($invoice));
        $this->assertSame(2, $this->activityCount($invoice));
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::PaymentConfirmed->value,
        ]);
    }

    public function test_automatic_credit_during_issue_has_credit_and_issue_events_without_payment_status_events(): void
    {
        $invoice = $this->invoice('draft', 'ACT-2C-AUTO-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '100.00']);

        $this->actingAsPermissions([PermissionName::InvoicesIssue->value]);
        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        $this->assertSame(2, $this->activityCount($invoice));
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::InvoiceIssued->value,
        ]);
        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => CompanyActivityEventType::CreditApplied->value,
        ]);
        foreach ([
            'payment.confirmed',
            'invoice.paid',
            'invoice.partially_paid',
            'invoice.status_changed',
        ] as $eventType) {
            $this->assertDatabaseMissing('company_activity_events', [
                'company_id' => $invoice->company_id,
                'event_type' => $eventType,
            ]);
        }
    }

    public function test_credit_payment_cancellation_has_no_separate_credit_reversal_event(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-CREDIT-CANCEL');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $result = app(ApplyCreditToInvoice::class)->execute($invoice);

        app(CancelPayment::class)->execute(
            Payment::query()->findOrFail($result->paymentId),
            'Отмена применения баланса',
        );

        $this->assertSame([
            CompanyActivityEventType::CreditApplied->value,
            CompanyActivityEventType::PaymentCancelled->value,
        ], $this->eventTypes($invoice));
        $this->assertDatabaseMissing('company_activity_events', [
            'company_id' => $invoice->company_id,
            'event_type' => 'credit.reverted',
        ]);
    }

    public function test_web_and_api_payment_mutations_record_the_same_shared_events_with_actor(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-WEB-API');
        $actor = $this->actingAsPermissions([
            PermissionName::PaymentsCreate->value,
            PermissionName::PaymentsConfirm->value,
        ]);

        $this->post(route('payments.store', $invoice), [
            'payment_date' => '2026-08-01',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'status' => 'pending',
        ])->assertRedirect();
        $pending = Payment::query()->where('invoice_id', $invoice->id)->sole();

        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::PaymentPendingCreated->value,
        ]);
        $this->postJson(route('api.payments.confirm', $pending))
            ->assertOk();

        $this->assertDatabaseHas('company_activity_events', [
            'company_id' => $invoice->company_id,
            'actor_user_id' => $actor->id,
            'event_type' => CompanyActivityEventType::PaymentConfirmed->value,
        ]);
        $this->assertSame(2, $this->activityCount($invoice));
    }

    public function test_denied_payment_and_credit_mutations_commit_zero_events(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-DENIED');
        $this->actingAsPermissions([]);

        $this->post(route('payments.store', $invoice), [
            'payment_date' => '2026-08-01',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'status' => 'pending',
        ])->assertForbidden();

        $this->post(route('invoices.apply-credit', $invoice), [
            'amount' => '10.00',
            'expected_credit_balance_minor' => 1000,
            'expected_available_minor' => 10000,
        ])->assertForbidden();

        $this->assertSame(0, $this->activityCount($invoice));
    }

    public function test_stale_confirmation_and_insufficient_credit_commit_zero_events(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-FAILURES');
        $pending = app(CreatePendingPayment::class)->execute($invoice, [
            'payment_date' => '2026-08-01',
            'amount' => '25.00',
            'payment_method' => 'transfer',
        ]);

        app(ConfirmPayment::class)->execute($pending);
        try {
            app(ConfirmPayment::class)->execute($pending->fresh());
            $this->fail('A second confirmation should be rejected.');
        } catch (\Throwable) {
            // The stale action must not insert another event.
        }

        $otherInvoice = $this->invoice('issued', 'ACT-2C-NO-CREDIT');
        $otherInvoice->company->creditBalance()->create(['amount' => '1.00']);
        try {
            app(ApplyCreditToInvoice::class)->executeManual($otherInvoice, 2000, 100, 10000);
            $this->fail('An over-capacity Credit application should be rejected.');
        } catch (ValidationException) {
            // The failed application must not insert a Credit event.
        }

        $this->assertSame(2, $this->activityCount($invoice));
        $this->assertSame(0, $this->activityCount($otherInvoice));
    }

    public function test_recorder_failure_rolls_back_payment_mutation_and_activity(): void
    {
        $invoice = $this->invoice('issued', 'ACT-2C-ROLLBACK');
        $exception = new RuntimeException('activity recorder failed');
        $eventName = 'eloquent.creating: '.CompanyActivityEvent::class;
        Event::listen($eventName, static function () use ($exception): never {
            throw $exception;
        });

        try {
            app(CreatePendingPayment::class)->execute($invoice, [
                'payment_date' => '2026-08-01',
                'amount' => '25.00',
                'payment_method' => 'transfer',
            ]);
            $this->fail('The recorder failure should be propagated.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $this->activityCount($invoice));
    }

    /** @return list<string> */
    private function eventTypes(Invoice $invoice): array
    {
        return CompanyActivityEvent::query()
            ->where('company_id', $invoice->company_id)
            ->orderBy('id')
            ->pluck('event_type')
            ->all();
    }

    private function activityCount(Invoice $invoice): int
    {
        return CompanyActivityEvent::query()
            ->where('company_id', $invoice->company_id)
            ->count();
    }
}
