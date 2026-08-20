<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\PaymentController as ApiPaymentController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PaymentController;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Access\ApiRouteAuthorizationRegistry;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class FinancialRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    /** @var array<string, array{method: string, ability: string, target: class-string, scenario: string}> */
    private const AUTHORIZATION_MATRIX = [
        'invoices.store' => [
            'method' => 'POST',
            'ability' => 'create',
            'target' => Invoice::class,
            'scenario' => 'invoice_store',
        ],
        'invoices.update' => [
            'method' => 'PUT',
            'ability' => 'update',
            'target' => Invoice::class,
            'scenario' => 'invoice_update',
        ],
        'invoices.issue' => [
            'method' => 'POST',
            'ability' => 'issue',
            'target' => Invoice::class,
            'scenario' => 'invoice_issue',
        ],
        'invoices.apply-credit' => [
            'method' => 'POST',
            'ability' => 'create',
            'target' => Payment::class,
            'scenario' => 'invoice_apply_credit',
        ],
        'invoices.cancel' => [
            'method' => 'PATCH',
            'ability' => 'cancel',
            'target' => Invoice::class,
            'scenario' => 'invoice_cancel',
        ],
        'invoices.destroy' => [
            'method' => 'DELETE',
            'ability' => 'delete',
            'target' => Invoice::class,
            'scenario' => 'invoice_destroy',
        ],
        'payments.store' => [
            'method' => 'POST',
            'ability' => 'create',
            'target' => Payment::class,
            'scenario' => 'payment_store',
        ],
        'payments.confirm' => [
            'method' => 'PATCH',
            'ability' => 'confirm',
            'target' => Payment::class,
            'scenario' => 'payment_confirm',
        ],
        'payments.cancel' => [
            'method' => 'PATCH',
            'ability' => 'cancel',
            'target' => Payment::class,
            'scenario' => 'payment_cancel',
        ],
    ];

    public function test_every_financial_mutation_route_is_in_the_authorization_matrix(): void
    {
        $controllers = [
            InvoiceController::class,
            PaymentController::class,
        ];
        $stateChangingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) use ($controllers, $stateChangingMethods): bool {
                return in_array($route->getControllerClass(), $controllers, true)
                    && array_intersect($stateChangingMethods, $route->methods()) !== [];
            })
            ->keyBy(fn ($route): string => (string) $route->getName());

        $this->assertSame(
            collect(array_keys(self::AUTHORIZATION_MATRIX))->sort()->values()->all(),
            $routes->keys()->sort()->values()->all()
        );

        foreach (self::AUTHORIZATION_MATRIX as $routeName => $definition) {
            $this->assertContains($definition['method'], $routes->get($routeName)->methods());
            $this->assertNotSame('', $definition['ability']);
            $this->assertContains($definition['target'], [Invoice::class, Payment::class]);
        }
    }

    public function test_api_payment_confirmation_is_a_separate_permission_protected_command(): void
    {
        $route = Route::getRoutes()->getByName('api.payments.confirm');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('api/payments/{payment}/confirm', $route->uri());
        $this->assertSame(ApiPaymentController::class, $route->getControllerClass());
        $this->assertSame('payments.confirm', ApiRouteAuthorizationRegistry::permissionFor('api.payments.confirm'));
    }

    /**
     * @param  array{method: string, ability: string, target: class-string, scenario: string}  $definition
     */
    #[DataProvider('mutationProvider')]
    public function test_matrix_route_rejects_without_permission_and_preserves_database(
        string $routeName,
        array $definition
    ): void {
        $this->actingAsPermissions();

        match ($definition['scenario']) {
            'invoice_store' => $this->assertInvoiceStoreForbidden($routeName),
            'invoice_update' => $this->assertInvoiceUpdateForbidden($routeName),
            'invoice_issue' => $this->assertInvoiceIssueForbidden($routeName),
            'invoice_apply_credit' => $this->assertInvoiceApplyCreditForbidden($routeName),
            'invoice_cancel' => $this->assertInvoiceCancelForbidden($routeName),
            'invoice_destroy' => $this->assertInvoiceDestroyForbidden($routeName),
            'payment_store' => $this->assertPaymentStoreForbidden($routeName),
            'payment_confirm' => $this->assertPaymentConfirmForbidden($routeName),
            'payment_cancel' => $this->assertPaymentCancelForbidden($routeName),
        };
    }

    /** @return array<string, array{string, array{method: string, ability: string, target: class-string, scenario: string}}> */
    public static function mutationProvider(): array
    {
        $cases = [];

        foreach (self::AUTHORIZATION_MATRIX as $routeName => $definition) {
            $cases[$routeName] = [$routeName, $definition];
        }

        return $cases;
    }

    private function assertInvoiceStoreForbidden(string $routeName): void
    {
        $company = $this->company('Forbidden invoice store');
        $contract = $this->contract($company);
        $invoiceCount = DB::table('invoices')->count();
        $lineCount = DB::table('invoice_lines')->count();

        $this->post(route($routeName), $this->invoiceStorePayload($company, $contract))
            ->assertForbidden();

        $this->assertSame($invoiceCount, DB::table('invoices')->count());
        $this->assertSame($lineCount, DB::table('invoice_lines')->count());
    }

    private function assertInvoiceUpdateForbidden(string $routeName): void
    {
        $invoice = $this->invoice('draft', 'FORBIDDEN-UPDATE');
        $line = $invoice->lines()->firstOrFail();
        $payload = $this->invoiceUpdatePayload($invoice);
        $payload['issue_date'] = '2026-07-02';
        $payload['lines'][0]['description'] = 'Mutated description';
        $payload['lines'][0]['amount'] = '125.00';

        $this->put(route($routeName, $invoice), $payload)->assertForbidden();

        $this->assertSame('2026-07-01', $invoice->fresh()->getRawOriginal('issue_date'));
        $this->assertSame('100.00', $invoice->fresh()->getRawOriginal('total_amount'));
        $this->assertSame('Authorization line', $line->fresh()->description);
        $this->assertSame('100.00', $line->fresh()->getRawOriginal('amount'));
        $this->assertDatabaseCount('invoice_lines', 1);
    }

    private function assertInvoiceIssueForbidden(string $routeName): void
    {
        $invoice = $this->invoice('draft', 'FORBIDDEN-ISSUE');
        $paymentCount = DB::table('payments')->count();
        $allocationCount = DB::table('payment_allocations')->count();
        $entryCount = DB::table('credit_balance_entries')->count();

        $this->post(route($routeName, $invoice))->assertForbidden();

        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame($paymentCount, DB::table('payments')->count());
        $this->assertSame($allocationCount, DB::table('payment_allocations')->count());
        $this->assertSame($entryCount, DB::table('credit_balance_entries')->count());
    }

    private function assertInvoiceApplyCreditForbidden(string $routeName): void
    {
        $invoice = $this->invoice('issued', 'FORBIDDEN-APPLY-CREDIT');
        $balance = $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $paymentCount = DB::table('payments')->count();
        $entryCount = DB::table('credit_balance_entries')->count();

        $this->post(route($routeName, $invoice), $this->applyCreditPayload())
            ->assertForbidden();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('50.00', $balance->fresh()->getRawOriginal('amount'));
        $this->assertSame($paymentCount, DB::table('payments')->count());
        $this->assertSame($entryCount, DB::table('credit_balance_entries')->count());
    }

    public function test_apply_credit_allows_invoice_payment_and_financial_permissions(): void
    {
        $invoice = $this->invoice('issued', 'ALLOWED-APPLY-CREDIT');
        $balance = $invoice->company->creditBalance()->create(['amount' => '50.00']);
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCreate->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);

        $this->post(route('invoices.apply-credit', $invoice), $this->applyCreditPayload())
            ->assertRedirect(route('invoices.show', $invoice));

        $payment = $invoice->payments()->sole();
        $this->assertSame('30.00', $payment->getRawOriginal('amount'));
        $this->assertDatabaseHas('credit_balance_entries', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'type' => 'applied',
            'amount' => '30.00',
        ]);
        $this->assertSame('20.00', $balance->fresh()->getRawOriginal('amount'));
    }

    public function test_apply_credit_requires_payment_create_permission(): void
    {
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::CompaniesFinancialsView->value,
        ]);

        $this->assertInvoiceApplyCreditForbidden('invoices.apply-credit');
    }

    public function test_apply_credit_requires_company_financial_visibility(): void
    {
        $this->actingAsPermissions([
            PermissionName::InvoicesView->value,
            PermissionName::PaymentsCreate->value,
        ]);

        $this->assertInvoiceApplyCreditForbidden('invoices.apply-credit');
    }

    /** @return array<string, string> */
    private function applyCreditPayload(): array
    {
        return [
            'amount' => '30.00',
            'expected_credit_balance_minor' => '5000',
            'expected_available_minor' => '10000',
        ];
    }

    private function assertInvoiceCancelForbidden(string $routeName): void
    {
        $invoice = $this->invoice('issued', 'FORBIDDEN-CANCEL');
        $lineCount = DB::table('invoice_lines')->count();

        $this->patch(route($routeName, $invoice))->assertForbidden();

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame($lineCount, DB::table('invoice_lines')->count());
    }

    private function assertInvoiceDestroyForbidden(string $routeName): void
    {
        $invoice = $this->invoice('draft', 'FORBIDDEN-DESTROY');
        $line = $invoice->lines()->firstOrFail();

        $this->delete(route($routeName, $invoice))->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertDatabaseHas('invoice_lines', ['id' => $line->id, 'invoice_id' => $invoice->id]);
    }

    private function assertPaymentStoreForbidden(string $routeName): void
    {
        foreach (['pending', 'confirmed'] as $status) {
            $invoice = $this->invoice('issued', 'FORBIDDEN-PAYMENT-'.strtoupper($status));
            $paymentCount = DB::table('payments')->count();
            $allocationCount = DB::table('payment_allocations')->count();
            $entryCount = DB::table('credit_balance_entries')->count();
            $balanceCount = DB::table('credit_balances')->count();

            $this->post(route($routeName, $invoice), $this->validPaymentPayload($status))
                ->assertForbidden();

            $this->assertSame($paymentCount, DB::table('payments')->count());
            $this->assertSame($allocationCount, DB::table('payment_allocations')->count());
            $this->assertSame($entryCount, DB::table('credit_balance_entries')->count());
            $this->assertSame($balanceCount, DB::table('credit_balances')->count());
            $this->assertSame('issued', $invoice->fresh()->status);
            $this->assertSame('100.00', $invoice->fresh()->getRawOriginal('total_amount'));
        }
    }

    private function assertPaymentConfirmForbidden(string $routeName): void
    {
        $invoice = $this->invoice('issued', 'FORBIDDEN-CONFIRM');
        $payment = $this->payment($invoice, 'pending');
        $allocationCount = DB::table('payment_allocations')->count();
        $entryCount = DB::table('credit_balance_entries')->count();
        $balanceCount = DB::table('credit_balances')->count();

        $this->patch(route($routeName, $payment))->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame($allocationCount, DB::table('payment_allocations')->count());
        $this->assertSame($entryCount, DB::table('credit_balance_entries')->count());
        $this->assertSame($balanceCount, DB::table('credit_balances')->count());
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $invoice->fresh()->getRawOriginal('total_amount'));
    }

    private function assertPaymentCancelForbidden(string $routeName): void
    {
        $invoice = $this->invoice('issued', 'FORBIDDEN-PAYMENT-CANCEL');
        $payment = $this->payment($invoice, 'confirmed');
        $allocationCount = DB::table('payment_allocations')->count();
        $entryCount = DB::table('credit_balance_entries')->count();
        $balanceCount = DB::table('credit_balances')->count();

        $this->patch(route($routeName, $payment), [
            'cancel_payment_id' => $payment->id,
            'cancel_reason' => 'Forbidden cancellation',
        ])->assertForbidden();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->cancelled_at);
        $this->assertNull($payment->fresh()->cancel_reason);
        $this->assertSame($allocationCount, DB::table('payment_allocations')->count());
        $this->assertSame($entryCount, DB::table('credit_balance_entries')->count());
        $this->assertSame($balanceCount, DB::table('credit_balances')->count());
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame('100.00', $invoice->fresh()->getRawOriginal('total_amount'));
    }
}
