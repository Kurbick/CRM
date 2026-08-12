<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthenticatedTestCase as TestCase;

class OrderPaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_selector_links_to_typed_order_form(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();

        $response = $this->get(route('contracts.subjects.create', $contract))->assertOk();
        $response->assertSee(route('contracts.orders.create', $contract), false)
            ->assertSee(route('contracts.subscriptions.create', $contract), false)
            ->assertDontSee('name="payment_terms"', false);
    }

    public function test_order_create_form_is_empty_and_has_no_deadline(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();
        $response = $this->get(route('contracts.orders.create', $contract))->assertOk();

        $response->assertSee('Срок оплаты (дней)')->assertDontSee('Дедлайн')->assertDontSee('Срок выполнения');
        $this->assertDoesNotMatchRegularExpression('/name="payment_terms"[^>]*value="14"/', $response->getContent());
    }

    public function test_typed_order_requires_terms_and_saves_exact_zero_or_positive_value(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();
        $base = ['service_name' => 'Разработка сайта', 'order_date' => '2026-08-01', 'price' => '100.00', 'status' => 'in_progress'];

        $this->post(route('contracts.orders.store', $contract), $base)
            ->assertSessionHasErrors('payment_terms');
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('contracts.orders.store', $contract), [...$base, 'payment_terms' => 30])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(30, (int) Order::query()->sole()->payment_terms);
        $this->assertNull(Order::query()->sole()->deadline);

        Order::query()->delete();
        $this->post(route('contracts.orders.store', $contract), [...$base, 'payment_terms' => 0])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(0, (int) Order::query()->sole()->payment_terms);
    }

    #[DataProvider('invalidTerms')]
    public function test_invalid_order_terms_are_rejected(mixed $terms): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();
        $this->post(route('contracts.orders.store', $contract), [
            'service_name' => 'Order', 'order_date' => '2026-08-01',
            'price' => '10.00', 'payment_terms' => $terms, 'status' => 'in_progress',
        ])->assertSessionHasErrors('payment_terms');
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidTerms(): array
    {
        return ['negative' => [-1], 'fraction' => ['1.5'], 'text' => ['days'], 'too large' => [3651]];
    }

    public function test_validation_error_preserves_entered_terms(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();
        $this->from(route('contracts.orders.create', $contract))
            ->post(route('contracts.orders.store', $contract), [
                'service_name' => '', 'order_date' => '2026-08-01',
                'price' => '10.00', 'payment_terms' => 30, 'status' => 'in_progress',
            ])->assertRedirect(route('contracts.orders.create', $contract));

        $this->get(route('contracts.orders.create', $contract))
            ->assertSee('name="payment_terms" value="30"', false);
    }

    public function test_edit_shows_saved_terms_without_deadline_and_update_requires_exact_value(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsUpdate);
        [, $contract] = $this->companyAndContract();
        $order = $contract->orders()->create($this->orderAttributes(30));
        $invoice = Invoice::create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-ORDER-EDIT',
            'issue_date' => '2026-07-22',
            'due_date' => '2026-08-21',
            'total_amount' => 100,
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'description' => 'Website',
            'amount' => 100,
            'order_id' => $order->id,
        ]);

        $response = $this->get(route('orders.edit', $order))->assertOk();
        $response->assertSee('name="payment_terms" value="30"', false)->assertDontSee('Дедлайн')->assertDontSee('Срок выполнения');

        $payload = ['title' => 'Updated', 'order_date' => '2026-08-01', 'price' => '100.00', 'payment_terms' => 7, 'status' => 'in_progress'];
        $this->put(route('orders.update', $order), $payload)->assertSessionDoesntHaveErrors();
        $this->assertSame(7, (int) $order->fresh()->payment_terms);
        $this->assertSame('2026-07-29', $invoice->fresh()->due_date);

        $this->put(route('orders.update', $order), [...$payload, 'payment_terms' => null])
            ->assertSessionHasErrors('payment_terms');
        $this->assertSame(7, (int) $order->fresh()->payment_terms);
    }

    public function test_edit_does_not_invent_fourteen_for_null_model_value(): void
    {
        [, $contract] = $this->companyAndContract();
        $order = new Order($this->orderAttributes(30));
        $order->id = 999;
        $order->payment_terms = null;
        $order->setRelation('contract', $contract);

        $errors = new ViewErrorBag;
        $backUrl = route('home');
        $html = view('orders.edit', compact('order', 'contract', 'errors', 'backUrl'))->render();
        $this->assertStringContainsString('name="payment_terms" value=""', $html);
        $this->assertStringNotContainsString('name="payment_terms" value="14"', $html);
    }

    public function test_subscription_flow_keeps_its_own_terms_and_does_not_create_order(): void
    {
        $this->grantSubjectPermission(PermissionName::ContractSubjectsCreate);
        [, $contract] = $this->companyAndContract();
        $this->post(route('contracts.subscriptions.store', $contract), [
            'service_name' => 'Support', 'start_date' => '2026-08-01',
            'billing_period' => 'monthly', 'amount' => '50.00', 'payment_terms' => 30,
            'status' => 'active', 'order_date' => '2026-01-01', 'price' => '999.00',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('subscriptions', ['contract_id' => $contract->id, 'payment_terms' => 30]);
    }

    private function companyAndContract(): array
    {
        $company = Company::create(['name' => 'Order Terms Company', 'status' => 'active']);
        $contract = Contract::create(['company_id' => $company->id, 'contract_number' => 'ORDER-TERMS', 'start_date' => '2026-01-01', 'status' => 'active']);

        return [$company, $contract];
    }

    private function grantSubjectPermission(PermissionName $permission): void
    {
        $this->authenticatedUser->givePermissionTo($permission->value);
    }

    private function orderAttributes(int $terms): array
    {
        return ['title' => 'Website', 'order_date' => '2026-08-01', 'price' => '100.00', 'payment_terms' => $terms, 'status' => 'in_progress'];
    }
}
