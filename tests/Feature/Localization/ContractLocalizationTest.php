<?php

namespace Tests\Feature\Localization;

use App\Models\Contract;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContractLocalizationTest extends AuthorizationTestCase
{
    public function test_contract_context_keeps_the_approved_russian_presentation(): void
    {
        [$contract, $order, $subscription] = $this->contractContext();

        $this->withSession(['locale' => 'ru']);

        $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSeeText('Новый договор')
            ->assertSeeText('Основная информация')
            ->assertSeeText('Активный');

        $this->get(route('contracts.edit', $contract))
            ->assertOk()
            ->assertSeeText('Редактирование договора')
            ->assertSeeText('Сохранить');

        $this->get(route('contracts.subjects.create', $contract))
            ->assertOk()
            ->assertSeeText('Добавить предмет договора')
            ->assertSeeText('Разовая услуга')
            ->assertSeeText('Подписка');

        $this->get(route('contracts.orders.create', $contract))
            ->assertOk()
            ->assertSeeText('Разовая услуга')
            ->assertSeeText('Срок оплаты (дней)');

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSeeText('Редактирование разовой услуги');

        $this->get(route('contracts.subscriptions.create', $contract))
            ->assertOk()
            ->assertSeeText('Подписка')
            ->assertSeeText('Ежемесячно');

        $this->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertSeeText('Редактирование подписки');

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSeeText('Документы')
            ->assertSee('aria-label="Скачать документ"', false)
            ->assertSee('aria-label="Удалить документ"', false)
            ->assertSeeText('Предмет договора')
            ->assertSeeText('Разовая услуга')
            ->assertSeeText('Подписка');
    }

    public function test_contract_context_uses_approved_azerbaijani_terminology(): void
    {
        [$contract, $order, $subscription] = $this->contractContext();

        $this->withSession(['locale' => 'az']);

        $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSeeText('Yeni müqavilə')
            ->assertSeeText('Əsas məlumat')
            ->assertSeeText('Aktiv');

        $this->get(route('contracts.edit', $contract))
            ->assertOk()
            ->assertSeeText('Müqaviləyə düzəliş et')
            ->assertSeeText('Yadda saxla');

        $subjectResponse = $this->get(route('contracts.subjects.create', $contract))
            ->assertOk()
            ->assertSeeText('Müqaviləyə xidmət əlavə et')
            ->assertSeeText('Birdəfəlik xidmət')
            ->assertSeeText('Abunəlik');
        $this->assertStringNotContainsString('predmet', mb_strtolower($subjectResponse->getContent()));

        $this->get(route('contracts.orders.create', $contract))
            ->assertOk()
            ->assertSeeText('Birdəfəlik xidmət')
            ->assertSeeText('Yadda saxla')
            ->assertSeeText('Ləğv et');

        $orderEditResponse = $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSeeText('Birdəfəlik xidmətə düzəliş et');
        $this->assertStringContainsString('düzəliş et', mb_strtolower($orderEditResponse->getContent()));

        $this->get(route('contracts.subscriptions.create', $contract))
            ->assertOk()
            ->assertSeeText('Abunəlik')
            ->assertSeeText('Aylıq');

        $this->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertSeeText('Abunəliyə düzəliş et')
            ->assertSeeText('Yadda saxla');

        $this->get(route('contracts.show', $contract))
            ->assertOk()
            ->assertSeeText('Sənədlər')
            ->assertSee('aria-label="Sənədi endir"', false)
            ->assertSee('aria-label="Sənədi sil"', false)
            ->assertSeeText('Xidmətlər')
            ->assertSeeText('Birdəfəlik xidmət')
            ->assertSeeText('Abunəlik');
    }

    public function test_contract_context_routes_and_permissions_are_unchanged_by_locale(): void
    {
        [$contract] = $this->contractContext();

        $this->withSession(['locale' => 'az']);

        $this->get(route('contracts.subjects.create', $contract))
            ->assertOk()
            ->assertSee(route('contracts.orders.create', $contract), false)
            ->assertSee(route('contracts.subscriptions.create', $contract), false);

        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $this->get(route('contracts.subjects.create', $contract))->assertForbidden();
        $this->get(route('contracts.orders.create', $contract))->assertForbidden();
        $this->get(route('contracts.subscriptions.create', $contract))->assertForbidden();
    }

    /** @return array{0: Contract, 1: \App\Models\Order, 2: \App\Models\Subscription} */
    private function contractContext(): array
    {
        $company = $this->company('Localization company');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract);
        $subscription = $this->subjectSubscription($contract);
        $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'localization.pdf',
            'file_path' => 'contract-documents/localization/localization.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $this->actingAsPermissions([
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
            PermissionName::ContractsCreate->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::ContractsDelete->value,
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractSubjectsUpdate->value,
            PermissionName::ContractSubjectsDelete->value,
            PermissionName::ContractDocumentsDownload->value,
            PermissionName::ContractDocumentsUpload->value,
            PermissionName::ContractDocumentsDelete->value,
        ]);

        return [$contract, $order, $subscription];
    }
}
