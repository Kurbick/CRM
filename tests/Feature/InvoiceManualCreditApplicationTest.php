<?php

namespace Tests\Feature;

use App\Actions\Credits\ApplyCreditToInvoice;
use App\Actions\Payments\CancelPayment;
use App\Models\CreditBalanceEntry;
use App\Models\Payment;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class InvoiceManualCreditApplicationTest extends AuthorizationTestCase
{
    public function test_permitted_operator_can_apply_exact_credit_amount_from_invoice_show(): void
    {
        $invoice = $this->invoice('issued', 'MANUAL-CREDIT');
        $balance = $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions($this->creditPermissions());

        $response = $this->get(route('invoices.show', $invoice));

        $response
            ->assertOk()
            ->assertSee('Баланс компании')
            ->assertSee('Оплатить с баланса')
            ->assertSee('Оплата с баланса')
            ->assertSee('Инвойс '.$invoice->invoice_number)
            ->assertSee('Задолженность по инвойсу:')
            ->assertSee('Максимальная сумма оплаты с баланса:')
            ->assertSee('Сумма оплаты (₼)')
            ->assertSee('Отмена')
            ->assertDontSee('rounded-full bg-blue-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-blue-700', false)
            ->assertDontSee('Использовать баланс')
            ->assertSee('expected_credit_balance_minor', false)
            ->assertSee('expected_available_minor', false);

        $this->assertMatchesRegularExpression(
            '/<button type="submit"[^>]*>\s*Оплатить\s*<\/button>/u',
            $response->getContent(),
        );

        $response = $this->post(route('invoices.apply-credit', $invoice), [
            'amount' => '30.00',
            'expected_credit_balance_minor' => 5000,
            'expected_available_minor' => 10000,
        ]);

        $response->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success', 'Из баланса применено 30,00 ₼.');
        $this->assertSame('20.00', $balance->fresh()->getRawOriginal('amount'));
        $payment = Payment::query()->where('invoice_id', $invoice->id)->sole();
        $entry = CreditBalanceEntry::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('30.00', $payment->getRawOriginal('amount'));
        $this->assertSame($payment->id, $entry->payment_id);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    public function test_stale_manual_form_reopens_dialog_without_second_payment(): void
    {
        $invoice = $this->invoice('issued', 'STALE-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions($this->creditPermissions());

        $this->post(route('invoices.apply-credit', $invoice), [
            'amount' => '30.00',
            'expected_credit_balance_minor' => 5000,
            'expected_available_minor' => 10000,
        ])->assertRedirect();

        $this->from(route('invoices.show', $invoice))->post(route('invoices.apply-credit', $invoice), [
            'amount' => '30.00',
            'expected_credit_balance_minor' => 5000,
            'expected_available_minor' => 10000,
        ])->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHasErrors([
                'credit_amount' => 'Финансовые данные изменились. Обновите страницу и попробуйте снова.',
            ])
            ->assertSessionHas('credit_dialog_open', true)
            ->assertSessionHasInput('amount', '30.00');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_invalid_manual_amount_reopens_dialog_and_preserves_input(): void
    {
        $invoice = $this->invoice('issued', 'INVALID-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions($this->creditPermissions());

        $this->from(route('invoices.show', $invoice))->post(route('invoices.apply-credit', $invoice), [
            'amount' => '0.00',
            'expected_credit_balance_minor' => 5000,
            'expected_available_minor' => 10000,
        ])->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHasErrors('credit_amount')
            ->assertSessionHas('credit_dialog_open', true)
            ->assertSessionHasInput('amount', '0.00');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_credit_balance_is_not_disclosed_or_usable_without_financial_permission(): void
    {
        $invoice = $this->invoice('issued', 'HIDDEN-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCreate->value,
        ]);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Баланс компании')
            ->assertDontSee('Оплатить с баланса');

        $this->post(route('invoices.apply-credit', $invoice), [
            'amount' => '30.00',
            'expected_credit_balance_minor' => 5000,
            'expected_available_minor' => 10000,
        ])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_fully_pending_invoice_shows_disabled_credit_action_explanation(): void
    {
        $invoice = $this->invoice('issued', 'PENDING-CREDIT');
        $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $pending = $this->payment($invoice, 'pending');
        $pending->forceFill(['amount' => '100.00'])->saveQuietly();
        $this->actingAsPermissions($this->creditPermissions());

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Баланс компании')
            ->assertSee('Весь остаток уже зарезервирован ожидающим платежом.')
            ->assertSee('disabled', false);
    }

    public function test_cancelling_one_manual_application_restores_only_that_credit_event(): void
    {
        $invoice = $this->invoice('issued', 'REVERSE-CREDIT');
        $balance = $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $action = app(ApplyCreditToInvoice::class);

        $first = $action->executeManual($invoice, 2000, 5000, 10000);
        $second = $action->executeManual($invoice->fresh(), 3000, 3000, 8000);

        app(CancelPayment::class)->execute(
            Payment::query()->findOrFail($second->paymentId),
            'Отмена второго применения Credit Balance',
        );

        $this->assertSame('30.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame('confirmed', Payment::query()->findOrFail($first->paymentId)->status);
        $this->assertSame('cancelled', Payment::query()->findOrFail($second->paymentId)->status);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'applied_reversal',
            'payment_id' => $second->paymentId,
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
        ]);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    /** @return list<string> */
    private function creditPermissions(): array
    {
        return [
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCreate->value,
            PermissionName::CompaniesFinancialsView->value,
        ];
    }
}
