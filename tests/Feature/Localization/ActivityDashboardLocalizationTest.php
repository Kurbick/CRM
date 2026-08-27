<?php

namespace Tests\Feature\Localization;

use App\Models\Company;
use App\Services\CompanyActivityRecorder;
use App\Support\Access\PermissionName;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivityVisibilityScope;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ActivityDashboardLocalizationTest extends AuthorizationTestCase
{
    public function test_dashboard_uses_the_approved_russian_and_azerbaijani_presentation(): void
    {
        $company = $this->company('L10N dashboard company');
        $this->actingAsPermissions($this->dashboardPermissions());

        $this->withSession(['locale' => 'ru']);
        $ru = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Дашборд')
            ->assertSeeText('Общая статистика по системе')
            ->assertSeeText('Финансы')
            ->assertSeeText('Компании')
            ->assertSeeText('Активные компании')
            ->assertSeeText('Активна');

        $this->withSession(['locale' => 'az']);
        $az = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Əsas səhifə')
            ->assertSeeText('Ümumi sistem statistikası')
            ->assertSeeText('Maliyyə')
            ->assertSeeText('Şirkətlər')
            ->assertSeeText('Aktiv şirkətlər')
            ->assertSeeText('Aktiv')
            ->assertSeeText('Borc')
            ->assertSeeText('Ödənilib');

        $this->assertStringNotContainsString('Dashboard', $az->getContent());
        $this->assertStringNotContainsString('predmet', mb_strtolower($az->getContent()));
        $this->assertStringNotContainsString('Redakt', $az->getContent());
        $this->assertStringContainsString('dashboard-financial-summary', $ru->getContent());
        $this->assertTrue($company->exists);
    }

    public function test_dashboard_permission_aware_fallback_is_localized_without_revealing_blocks(): void
    {
        $company = $this->company('Dashboard gated localization company');
        $this->actingAsPermissions([PermissionName::DashboardView->value]);

        $response = $this->withSession(['locale' => 'az'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Giriş')
            ->assertSeeText('Göstəricilərə baxmaq üçün lazımi hüquqlarınız yoxdur')
            ->assertDontSeeText($company->name)
            ->assertDontSeeText('Maliyyə');

        $this->assertStringNotContainsString('dashboard-financial-summary', $response->getContent());
    }

    public function test_activity_filter_and_event_presentation_are_localized_without_changing_identifiers(): void
    {
        $company = $this->company('Activity localization company');
        $this->activityFixtures($company);
        $this->actingAsPermissions($this->dashboardPermissions());

        $ru = $this->withSession(['locale' => 'ru'])
            ->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertOk()
            ->assertSeeText('Все события')
            ->assertSee('Контакты')
            ->assertSee('Договоры')
            ->assertSee('Инвойсы')
            ->assertSee('Платежи')
            ->assertSee('Документы')
            ->assertSeeText('Добавлен контакт AZ Contact')
            ->assertSeeText('Создан договор CTR-L10N-5')
            ->assertSeeText('Добавлена разовая услуга Audit service')
            ->assertSeeText('Загружен документ contract-l10n-5.pdf')
            ->assertSeeText('Инвойс INV-L10N-5 выставлен')
            ->assertSeeText('Платёж 600,00 ₼ подтверждён')
            ->assertSee('class="flex h-9 w-full items-center gap-2', false);

        $az = $this->withSession(['locale' => 'az'])
            ->get(route('companies.show', ['company' => $company, 'tab' => 'activity']))
            ->assertOk()
            ->assertSeeText('Bütün hadisələr')
            ->assertSee('Kontaktlar')
            ->assertSee('Müqavilələr')
            ->assertSee('İnvoyslar')
            ->assertSee('Ödənişlər')
            ->assertSee('Sənədlər')
            ->assertSeeText('Kontakt əlavə edildi: AZ Contact')
            ->assertSeeText('Müqavilə yaradıldı: CTR-L10N-5')
            ->assertSeeText('Müqaviləyə xidmət əlavə edildi: Audit service')
            ->assertSeeText('Sənəd əlavə edildi: contract-l10n-5.pdf')
            ->assertSeeText('İnvoys INV-L10N-5 rəsmiləşdirildi')
            ->assertSeeText('Ödəniş 600,00 ₼ təsdiqləndi')
            ->assertSeeText('Balansdan ödəniş: 600,00 ₼')
            ->assertSee('aria-label="Fəaliyyət filtri"', false)
            ->assertSee('class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"', false);

        $this->assertStringNotContainsString('predmet', mb_strtolower($az->getContent()));
        $this->assertStringNotContainsString('Redakt', $az->getContent());
        $this->assertStringContainsString('activity_category', $az->getContent());
        $this->assertStringContainsString('activity', $ru->getContent());
    }

    /** @return list<string> */
    private function dashboardPermissions(): array
    {
        return [
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::CompaniesFinancialsView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsView->value,
        ];
    }

    private function activityFixtures(Company $company): void
    {
        $recorder = app(CompanyActivityRecorder::class);
        $events = [
            [CompanyActivityEventType::ContactCreated, CompanyActivityCategory::Contacts, CompanyActivityVisibilityScope::Contacts, ['contact_name' => 'AZ Contact']],
            [CompanyActivityEventType::ContractCreated, CompanyActivityCategory::Contracts, CompanyActivityVisibilityScope::Contracts, ['contract_number' => 'CTR-L10N-5']],
            [CompanyActivityEventType::ContractSubjectCreated, CompanyActivityCategory::Contracts, CompanyActivityVisibilityScope::Contracts, ['subject_type' => 'one_time', 'subject_name' => 'Audit service']],
            [CompanyActivityEventType::DocumentUploaded, CompanyActivityCategory::Documents, CompanyActivityVisibilityScope::Documents, ['document_name' => 'contract-l10n-5.pdf']],
            [CompanyActivityEventType::InvoiceIssued, CompanyActivityCategory::Invoices, CompanyActivityVisibilityScope::Financials, ['invoice_number' => 'INV-L10N-5']],
            [CompanyActivityEventType::PaymentPendingCreated, CompanyActivityCategory::Payments, CompanyActivityVisibilityScope::Financials, []],
            [CompanyActivityEventType::PaymentConfirmed, CompanyActivityCategory::Payments, CompanyActivityVisibilityScope::Financials, ['amount_minor' => 60000, 'currency' => '₼']],
            [CompanyActivityEventType::CreditApplied, CompanyActivityCategory::Payments, CompanyActivityVisibilityScope::Financials, ['amount_minor' => 60000, 'currency' => '₼']],
        ];

        foreach ($events as [$type, $category, $scope, $metadata]) {
            $recorder->record($company, $type, $category, $scope, metadata: $metadata);
        }
    }
}
