<?php

namespace Tests\Feature\Authorization;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentAuthorizationTest extends AuthorizationTestCase
{
    public function test_create_permission_allows_pending_and_confirmed_but_not_confirm_or_cancel(): void
    {
        $pendingInvoice = $this->invoice('issued', 'CREATE-PENDING');
        $confirmedInvoice = $this->invoice('issued', 'CREATE-CONFIRMED');
        $existing = $this->payment($pendingInvoice);
        $this->actingAsPermissions([PermissionName::PaymentsCreate->value]);

        $pendingResponse = $this->post(route('payments.store', $pendingInvoice), $this->validPaymentPayload('pending'))
            ->assertRedirect(route('home'))->assertSessionHas('success');
        $confirmedResponse = $this->post(route('payments.store', $confirmedInvoice), $this->validPaymentPayload('confirmed'))
            ->assertRedirect(route('home'))->assertSessionHas('success');
        $this->assertDatabaseHas('payments', ['invoice_id' => $pendingInvoice->id, 'status' => 'pending']);
        $this->assertDatabaseHas('payments', ['invoice_id' => $confirmedInvoice->id, 'status' => 'confirmed']);
        $this->assertSafeLandingResponse($pendingResponse, $pendingInvoice);
        $this->assertSafeLandingResponse($confirmedResponse, $confirmedInvoice);
        $this->patch(route('payments.confirm', $existing))->assertForbidden();
        $this->patch(route('payments.cancel', $existing), [
            'cancel_payment_id' => $existing->id,
            'cancel_reason' => 'Not allowed',
        ])->assertForbidden();
    }

    public function test_confirm_and_cancel_permissions_do_not_grant_neighboring_actions(): void
    {
        $confirmInvoice = $this->invoice('issued', 'CONFIRM-ONLY');
        $confirmPayment = $this->payment($confirmInvoice);
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $confirmResponse = $this->patch(route('payments.confirm', $confirmPayment))
            ->assertRedirect(route('home'))->assertSessionHas('success');
        $this->assertSame('confirmed', $confirmPayment->fresh()->status);
        $this->assertSafeLandingResponse($confirmResponse, $confirmInvoice);
        $this->post(route('payments.store', $confirmInvoice), $this->validPaymentPayload('pending'))
            ->assertForbidden();
        $this->patch(route('payments.cancel', $confirmPayment), [
            'cancel_payment_id' => $confirmPayment->id,
            'cancel_reason' => 'Not allowed',
        ])->assertForbidden();

        $cancelInvoice = $this->invoice('issued', 'CANCEL-ONLY');
        $cancelPayment = $this->payment($cancelInvoice);
        $otherPending = $this->payment($cancelInvoice, 'pending', 'Other pending');
        $this->actingAsPermissions([PermissionName::PaymentsCancel->value]);

        $cancelResponse = $this->patch(route('payments.cancel', $cancelPayment), [
            'cancel_payment_id' => $cancelPayment->id,
            'cancel_reason' => 'Allowed cancellation',
        ])->assertRedirect(route('home'))->assertSessionHas('success');
        $this->assertSame('cancelled', $cancelPayment->fresh()->status);
        $this->assertSafeLandingResponse($cancelResponse, $cancelInvoice);
        $this->post(route('payments.store', $cancelInvoice), $this->validPaymentPayload('pending'))
            ->assertForbidden();
        $this->patch(route('payments.confirm', $otherPending))->assertForbidden();
    }

    public function test_custom_role_is_authorized_by_payment_permission(): void
    {
        $invoice = $this->invoice('issued');
        $payment = $this->payment($invoice);
        $this->actingAsCustomRole([PermissionName::PaymentsConfirm->value]);

        $this->patch(route('payments.confirm', $payment))
            ->assertRedirect(route('home'));
        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    public function test_mutation_only_business_denial_keeps_payment_and_allocations_unchanged(): void
    {
        $invoice = $this->invoice('issued', 'MUTATION-DENIAL');
        $payment = $this->payment($invoice, 'confirmed');
        $allocationIds = $payment->allocations()->pluck('id')->all();
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $this->from(route('home'))->patch(route('payments.confirm', $payment))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('payment_confirm');

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame($allocationIds, $payment->allocations()->pluck('id')->all());
        $this->get(route('home'))->assertOk();
    }

    public function test_administrator_passes_policy_but_cannot_confirm_twice(): void
    {
        $invoice = $this->invoice('issued');
        $payment = $this->payment($invoice, 'confirmed');
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator, 'web');
        $allocationCount = $payment->allocations()->count();

        $this->assertTrue(Gate::forUser($administrator)->allows('confirm', $payment));
        $this->from(route('invoices.show', $invoice))
            ->patch(route('payments.confirm', $payment))
            ->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame($allocationCount, $payment->allocations()->count());
    }

    public function test_payments_view_controls_detailed_history_independently_from_create(): void
    {
        $invoice = $this->invoice('issued');
        $payment = $this->payment($invoice, 'pending', 'SECRET-PAYMENT-COMMENT');
        $payment->forceFill([
            'payment_date' => '2026-06-17',
            'amount' => '87.65',
            'payment_method' => 'card',
        ])->saveQuietly();
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCreate->value,
        ]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Зарегистрировать платеж')
            ->assertDontSee('SECRET-PAYMENT-COMMENT')
            ->assertDontSee('История платежей')
            ->assertDontSee('87,65')
            ->assertDontSee('17/06/2026')
            ->assertDontSee('Карта');

        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
        ]);
        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('SECRET-PAYMENT-COMMENT')
            ->assertSee('История платежей')
            ->assertDontSee('Зарегистрировать платеж')
            ->assertDontSee('Подтвердить платёж')
            ->assertDontSee('Отменить платёж');
    }

    public function test_confirm_action_is_available_without_payment_history_or_details(): void
    {
        $invoice = $this->invoice('issued', 'CONFIRM-ACTION-ONLY');
        $payment = $this->payment($invoice, 'pending', 'CONFIRM-ACTION-SECRET');
        $payment->forceFill([
            'payment_date' => '2026-06-18',
            'amount' => '86.54',
            'payment_method' => 'card',
        ])->saveQuietly();
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsConfirm->value,
        ]);

        $response = $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertViewMissing('paymentSource')
            ->assertViewMissing('paymentsById')
            ->assertSee('Доступные действия с платежами')
            ->assertSee("Платёж #{$payment->id}")
            ->assertSee('Подтвердить платёж')
            ->assertDontSee('Отменить платёж')
            ->assertDontSee('История платежей')
            ->assertDontSee('CONFIRM-ACTION-SECRET')
            ->assertDontSee('86,54')
            ->assertDontSee('18/06/2026')
            ->assertDontSee('Карта');

        $this->assertFalse($response->viewData('invoice')->relationLoaded('payments'));
    }

    public function test_cancel_action_is_available_without_history_and_forbidden_states_stay_hidden(): void
    {
        $invoice = $this->invoice('issued', 'CANCEL-ACTION-ONLY');
        $pending = $this->payment($invoice, 'pending', 'CANCEL-ACTION-SECRET');
        $pending->forceFill([
            'payment_date' => '2026-06-19',
            'amount' => '76.43',
            'payment_method' => 'cash',
        ])->saveQuietly();
        $cancelled = $this->payment($invoice, 'cancelled', 'CANCELLED-ACTION-SECRET');
        $creditPayment = $this->payment($invoice, 'confirmed', 'CREDIT-ACTION-SECRET');
        $creditBalanceId = DB::table('credit_balances')->insertGetId([
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
        ]);
        DB::table('credit_balance_entries')->insert([
            'credit_balance_id' => $creditBalanceId,
            'type' => 'applied',
            'amount' => '25.00',
            'payment_id' => $creditPayment->id,
            'invoice_id' => $invoice->id,
            'description' => 'CREDIT-BALANCE-ACTION-DETAIL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCancel->value,
        ]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Доступные действия с платежами')
            ->assertSee("Платёж #{$pending->id}")
            ->assertSee('Отменить платёж')
            ->assertDontSee('Подтвердить платёж')
            ->assertDontSee("Платёж #{$cancelled->id}")
            ->assertSee("Платёж #{$creditPayment->id}")
            ->assertSee(route('payments.cancel', $creditPayment), false)
            ->assertDontSee('История платежей')
            ->assertDontSee('CANCEL-ACTION-SECRET')
            ->assertDontSee('CANCELLED-ACTION-SECRET')
            ->assertDontSee('CREDIT-ACTION-SECRET')
            ->assertDontSee('CREDIT-BALANCE-ACTION-DETAIL')
            ->assertDontSee('Из баланса')
            ->assertDontSee('76,43')
            ->assertDontSee('19/06/2026')
            ->assertDontSee('Наличные');
    }

    private function assertSafeLandingResponse($response, Invoice $invoice): void
    {
        $location = (string) $response->headers->get('Location');
        $this->assertSame(route('home'), $location);
        $this->assertStringNotContainsString('/invoices/'.$invoice->id, $location);
        $this->assertStringNotContainsString('/companies/'.$invoice->company_id, $location);
        $this->assertNotSame(route('dashboard'), $location);
        $this->get($location)->assertOk();
    }

    public function test_applied_reversal_does_not_restore_cancel_action_without_payment_history(): void
    {
        $invoice = $this->invoice('issued', 'APPLIED-REVERSAL-ACTION-ONLY');
        $payment = $this->payment($invoice, 'pending', 'APPLIED-REVERSAL-SECRET');
        $payment->forceFill([
            'payment_date' => '2026-06-20',
            'amount' => '64.32',
            'payment_method' => 'card',
        ])->saveQuietly();
        $creditBalanceId = DB::table('credit_balances')->insertGetId([
            'company_id' => $invoice->company_id,
            'amount' => '0.00',
        ]);
        DB::table('credit_balance_entries')->insert([
            [
                'credit_balance_id' => $creditBalanceId,
                'type' => 'applied',
                'amount' => '10.00',
                'payment_id' => $payment->id,
                'invoice_id' => null,
                'description' => 'APPLIED-ENTRY-SECRET',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'credit_balance_id' => $creditBalanceId,
                'type' => 'applied_reversal',
                'amount' => '10.00',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'description' => 'APPLIED-REVERSAL-ENTRY-SECRET',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCancel->value,
        ]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee("Платёж #{$payment->id}")
            ->assertDontSee(route('payments.cancel', $payment), false)
            ->assertDontSee('APPLIED-REVERSAL-SECRET')
            ->assertDontSee('APPLIED-ENTRY-SECRET')
            ->assertDontSee('APPLIED-REVERSAL-ENTRY-SECRET')
            ->assertDontSee('64,32')
            ->assertDontSee('64.32')
            ->assertDontSee('20/06/2026')
            ->assertDontSee('2026-06-20')
            ->assertDontSee('card')
            ->assertDontSee('Карта');
    }

    public function test_legacy_credit_balance_comment_hides_cancel_action_without_disclosing_comment(): void
    {
        $invoice = $this->invoice('issued', 'LEGACY-CREDIT-ACTION-ONLY');
        $payment = $this->payment(
            $invoice,
            'pending',
            'Автоматически применён Credit Balance — SECRET-LEGACY-CREDIT'
        );
        $payment->forceFill([
            'payment_date' => '2026-06-21',
            'amount' => '63.41',
            'payment_method' => 'card',
        ])->saveQuietly();
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCancel->value,
        ]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee("Платёж #{$payment->id}")
            ->assertDontSee(route('payments.cancel', $payment), false)
            ->assertDontSee('SECRET-LEGACY-CREDIT')
            ->assertDontSee('Автоматически применён Credit Balance')
            ->assertDontSee('63,41')
            ->assertDontSee('63.41')
            ->assertDontSee('21/06/2026')
            ->assertDontSee('2026-06-21')
            ->assertDontSee('card')
            ->assertDontSee('Карта');
    }

    public function test_payment_buttons_require_their_permissions_and_allowed_state(): void
    {
        $invoice = $this->invoice('issued');
        $pending = $this->payment($invoice);
        $base = [
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
        ];

        $this->actingAsPermissions([...$base, PermissionName::PaymentsConfirm->value]);
        $this->get(route('invoices.show', $invoice))
            ->assertSee('Подтвердить платёж')
            ->assertDontSee('Отменить платёж');

        $this->actingAsPermissions([...$base, PermissionName::PaymentsCancel->value]);
        $this->get(route('invoices.show', $invoice))
            ->assertDontSee('Подтвердить платёж')
            ->assertSee('Отменить платёж');

        $confirmedInvoice = $this->invoice('paid', 'CONFIRMED-UI');
        $this->payment($confirmedInvoice, 'confirmed');
        $this->actingAsPermissions([
            ...$base,
            PermissionName::PaymentsConfirm->value,
        ]);
        $this->get(route('invoices.show', $confirmedInvoice))
            ->assertDontSee('Подтвердить платёж');
    }
}
