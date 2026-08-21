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
        $selectorResponse = $this->get($selectorUrl)
            ->assertOk()
            ->assertSee('Назад к договору')
            ->assertSee('data-testid="contract-subject-selector"', false)
            ->assertSee('data-testid="contract-subject-order-option"', false)
            ->assertSee('data-testid="contract-subject-subscription-option"', false)
            ->assertSee('data-testid="contract-subject-order-icon"', false)
            ->assertSee('data-testid="contract-subject-subscription-icon"', false)
            ->assertSee(route('contracts.orders.create', $contract))
            ->assertSee(route('contracts.subscriptions.create', $contract))
            ->assertDontSee('w-full max-w-2xl rounded-lg border border-gray-200 bg-white', false)
            ->assertDontSee('min-h-16 items-center rounded-lg', false);

        $selectorOptionClasses = 'group flex h-[68px] cursor-pointer items-center gap-3 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 transition hover:border-blue-400 hover:bg-blue-50/50 focus-visible:border-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 active:border-blue-600 active:bg-blue-50';
        $selectorMarkup = substr(
            $selectorResponse->getContent(),
            strpos($selectorResponse->getContent(), 'data-testid="contract-subject-selector"')
        );
        $selectorMarkup = substr($selectorMarkup, 0, strpos($selectorMarkup, '</section>'));
        $this->assertSame(2, substr_count($selectorMarkup, $selectorOptionClasses));
        $this->assertSame(2, substr_count($selectorMarkup, 'flex h-10 w-10 shrink-0 items-center justify-center rounded-md'));
        $this->assertSame(2, substr_count($selectorMarkup, '<svg class="h-5 w-5"'));
        $this->assertSame(2, substr_count($selectorMarkup, 'data-testid="contract-subject-choice-indicator"'));
        $this->assertSame(2, substr_count($selectorMarkup, 'group-active:scale-100'));
        $this->assertStringNotContainsString('M5 13l4 4L19 7', $selectorMarkup);
        $this->assertStringNotContainsString('Единоразовая работа', $selectorMarkup);
        $this->assertStringNotContainsString('Регулярные начисления', $selectorMarkup);

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
