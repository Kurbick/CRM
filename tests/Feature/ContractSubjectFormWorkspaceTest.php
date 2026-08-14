<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContractSubjectFormWorkspaceTest extends AuthorizationTestCase
{
    public function test_contract_and_subject_forms_keep_the_compact_workspace_contract(): void
    {
        $company = $this->company('Workspace Company');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract, ['title' => 'Workspace order']);
        $subscription = $this->subjectSubscription($contract, ['title' => 'Workspace subscription']);

        $this->actingAsPermissions([
            PermissionName::ContractsCreate->value,
            PermissionName::ContractsUpdate->value,
            PermissionName::ContractsView->value,
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractSubjectsUpdate->value,
        ]);

        $this->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('Новый договор')
            ->assertSee('Назад к договорам')
            ->assertSee('data-testid="contract-form-workspace"', false)
            ->assertSee('name="company_id"', false)
            ->assertSee('name="contract_number"', false)
            ->assertSee('grid grid-cols-1 gap-4 md:grid-cols-2', false);

        $this->get(route('contracts.edit', $contract))
            ->assertOk()
            ->assertSee('Редактирование договора')
            ->assertSee('Назад к договору')
            ->assertSee($contract->contract_number)
            ->assertSee($company->name)
            ->assertSee('data-testid="contract-form-workspace"', false);

        $selectorUrl = route('contracts.subjects.create', $contract);
        $this->get($selectorUrl)
            ->assertOk()
            ->assertSee('Назад к договору')
            ->assertSee('data-testid="contract-subject-selector"', false)
            ->assertSee(route('contracts.orders.create', $contract))
            ->assertSee(route('contracts.subscriptions.create', $contract))
            ->assertDontSee('w-full max-w-2xl rounded-lg border border-gray-200 bg-white', false)
            ->assertDontSee('min-h-16 items-center rounded-lg', false);

        $this->get(route('contracts.orders.create', $contract))
            ->assertOk()
            ->assertSee('Разовая услуга')
            ->assertSee('href="'.$selectorUrl.'"', false)
            ->assertSee('name="payment_terms"', false)
            ->assertSee('data-testid="one-time-service-form-workspace"', false);

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('Редактирование разовой услуги')
            ->assertSee('Назад к договору')
            ->assertDontSee('Дата заказа')
            ->assertSee('data-testid="one-time-service-form-workspace"', false);

        $this->get(route('contracts.subscriptions.create', $contract))
            ->assertOk()
            ->assertSee('Подписка')
            ->assertSee('href="'.$selectorUrl.'"', false)
            ->assertSee('name="billing_period"', false)
            ->assertSee('grid grid-cols-1 gap-2 sm:grid-cols-2', false)
            ->assertSee('data-testid="subscription-form-workspace"', false);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'FORM-WORKSPACE-LOCK',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => $subscription->title,
            'amount' => 100,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);

        $this->get(route('subscriptions.edit', $subscription))
            ->assertOk()
            ->assertSee('Редактирование подписки')
            ->assertSee('Назад к договору')
            ->assertSee('График нельзя изменить после добавления подписки в счёт.')
            ->assertSee('name="billing_period" value="monthly"', false)
            ->assertSee('data-testid="subscription-form-workspace"', false);
    }
}
