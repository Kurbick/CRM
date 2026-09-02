<?php

namespace Tests\Feature\Localization;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class InvoicePaymentLocalizationTest extends AuthorizationTestCase
{
    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testUser = $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
            PermissionName::InvoicesIssue->value,
            PermissionName::InvoicesCancel->value,
            PermissionName::InvoicesDelete->value,
            PermissionName::InvoicesPrint->value,
            PermissionName::PaymentsView->value,
            PermissionName::PaymentsCreate->value,
            PermissionName::PaymentsConfirm->value,
            PermissionName::PaymentsCancel->value,
        ]);
    }

    public function test_invoice_screens_keep_the_approved_russian_presentation(): void
    {
        $invoice = $this->invoice('issued', 'L10N4-RU');
        $invoice->company->creditBalance()->create(['amount' => '25.00']);
        $this->payment($invoice, 'pending');

        $this->withSession(['locale' => 'ru']);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSeeText('Инвойсы')
            ->assertSee('Состояние инвойса', false)
            ->assertSeeText('Расчётный период');

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Новый инвойс')
            ->assertSeeText('Основная информация')
            ->assertSeeText('Сохранить черновик');

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSeeText('Редактирование инвойса')
            ->assertSeeText('Сохранить');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Платежи')
            ->assertSeeText('Ожидает подтверждения')
            ->assertSeeText('Баланс компании')
            ->assertSeeText('Оплатить с баланса')
            ->assertSeeText('Сумма оплаты (₼)');
    }

    public function test_invoice_and_payment_screens_use_the_approved_azerbaijani_presentation(): void
    {
        $statuses = [
            'draft' => 'Qaralama',
            'issued' => 'Rəsmiləşdirilib',
            'partially_paid' => 'Qismən ödənilib',
            'paid' => 'Ödənilib',
            'cancelled' => 'Ləğv edilib',
        ];

        foreach (array_keys($statuses) as $status) {
            $this->invoice($status, 'L10N4-'.$status);
        }

        $invoice = $this->invoice('issued', 'L10N4-AZ-SHOW');
        $invoice->company->creditBalance()->create(['amount' => '25.00']);
        $this->payment($invoice, 'pending');

        $this->withSession(['locale' => 'az']);

        $index = $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSeeText('İnvoyslar')
            ->assertSee('İnvoys filtrləri', false)
            ->assertSeeText('STATUS')
            ->assertSeeText('ÖDƏNİŞ');
        foreach ($statuses as $label) {
            $index->assertSeeText($label);
        }

        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSeeText('Yeni invoys')
            ->assertSee('Birdəfəlik xidmət', false)
            ->assertSeeText('Qaralamanı yadda saxla');

        $edit = $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSeeText('İnvoysa düzəliş et');

        $show = $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Ödənişlər')
            ->assertSeeText('Təsdiq gözləyir')
            ->assertSeeText('Şirkətin balansı')
            ->assertSeeText('Balansdan ödə')
            ->assertSeeText('Balansdan ödəniş')
            ->assertSeeText('Ödəniş məbləği (₼)');

        $this->assertStringNotContainsString('İnvoysun vəziyyəti', $index->getContent());
        $this->assertStringNotContainsString('Redakt', $edit->getContent());
        $this->assertStringNotContainsString('Redakt', $show->getContent());
    }

    public function test_locale_does_not_change_invoice_routes_or_permissions(): void
    {
        $invoice = $this->invoice('draft', 'L10N4-AUTH');

        $this->withSession(['locale' => 'az']);

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.edit', $invoice), false);

        $this->testUser->revokePermissionTo(PermissionName::InvoicesUpdate->value);

        $this->get(route('invoices.edit', $invoice))->assertForbidden();
    }

    public function test_vat_breakdown_uses_the_approved_ru_and_az_labels(): void
    {
        $invoice = $this->invoice('issued', 'L10N4-VAT');
        $invoice->update([
            'subtotal_amount' => '100.00',
            'vat_enabled' => true,
            'vat_rate' => '18.00',
            'vat_amount' => '18.00',
            'total_amount' => '118.00',
        ]);

        $this->withSession(['locale' => 'ru'])
            ->get(route('invoices.show', $invoice))
            ->assertSeeText('Итого')
            ->assertSeeText('НДС (18%)')
            ->assertSeeText('Всего к оплате')
            ->assertDontSeeText('Сумма без НДС');

        $this->withSession(['locale' => 'az'])
            ->get(route('invoices.show', $invoice))
            ->assertSeeText('Cəmi')
            ->assertSeeText('ƏDV (18%)')
            ->assertSeeText('Ödəniləcək məbləğ')
            ->assertDontSeeText('ƏDV-siz məbləğ');
    }
}
