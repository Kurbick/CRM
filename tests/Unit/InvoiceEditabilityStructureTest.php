<?php

namespace Tests\Unit;

use Tests\TestCase;

class InvoiceEditabilityStructureTest extends TestCase
{
    public function test_invoice_creation_is_delegated_to_shared_action(): void
    {
        $web = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $api = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));
        $action = file_get_contents(app_path('Actions/Invoices/CreateInvoice.php'));

        $this->assertStringContainsString('$this->createInvoice->execute(', $web);
        $this->assertStringContainsString('$this->createInvoice->execute(', $api);
        $this->assertStringContainsString('DB::transaction(', $action);
        $this->assertStringContainsString('->lockForUpdate()', $action);
        $this->assertStringContainsString('billing_occurrence_key', $action);
        $this->assertStringContainsString('$this->sellerSnapshot->toArray($issuerOrganization)', $action);
    }

    public function test_web_update_locks_invoice_then_rechecks_shared_editability(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $update = file_get_contents(app_path('Actions/Invoices/UpdateInvoice.php'));
        $webUpdate = $this->methodSource($controller, 'public function update(', 'public function issue(');

        $this->assertStringContainsString('DB::transaction(', $update);
        $this->assertStringContainsString('->lockForUpdate()', $update);
        $this->assertStringContainsString('$this->editabilityService->evaluate($lockedInvoice)', $update);
        $this->assertLessThan(
            strpos($update, '$this->editabilityService->evaluate($lockedInvoice)'),
            strpos($update, '->lockForUpdate()')
        );
        $this->assertStringNotContainsString("\$invoiceData['status']", $update);
        $this->assertStringContainsString('Нельзя удалить связанную позицию из уже выставленного инвойса.', $update);
        $this->assertMatchesRegularExpression(
            '/\$this->updateInvoice->execute\s*\(\s*\$invoice\s*,/s',
            $webUpdate
        );
        $this->assertStringContainsString('preserveSubjectAmounts: true', $webUpdate);
        $this->assertLessThan(
            strpos($webUpdate, 'preserveSubjectAmounts: true'),
            strpos($webUpdate, '$this->updateInvoice->execute')
        );
    }

    public function test_api_update_uses_same_lock_and_explicitly_prohibits_protected_fields(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));
        $request = file_get_contents(app_path('Http/Requests/UpdateInvoiceRequest.php'));

        $this->assertStringContainsString('UpdateInvoice', $controller);
        $this->assertStringContainsString('$this->updateInvoice->execute($invoice', $controller);

        foreach (['status', 'total_amount', 'seller_name', 'payer_name', 'lines'] as $protectedField) {
            $this->assertStringContainsString("'{$protectedField}' => 'prohibited'", $request);
        }
    }

    public function test_web_filter_whitelist_contains_all_lifecycle_statuses(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $index = $this->methodSource($source, 'public function index(', 'public function create(');

        foreach (['draft', 'issued', 'partially_paid', 'paid', 'cancelled'] as $status) {
            $this->assertStringContainsString("'{$status}'", $index);
        }
        $this->assertStringContainsString('$this->normalizedStatuses($request, $allowedStatuses)', $index);
        $this->assertStringContainsString('$paginationParameters[\'statuses\']', $index);
    }

    public function test_invoice_update_checks_pending_total_under_invoice_lock_before_line_mutations(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $update = file_get_contents(app_path('Actions/Invoices/UpdateInvoice.php'));

        $lockPosition = strpos($update, '->lockForUpdate()');
        $availabilityPosition = strpos(
            $update,
            '$this->paymentAvailabilityService->evaluate($lockedInvoice)'
        );
        $lineMutationPosition = strpos($update, "->update([\n                        'description'");

        $this->assertLessThan($availabilityPosition, $lockPosition);
        $this->assertLessThan($lineMutationPosition, $availabilityPosition);
        $this->assertStringContainsString(
            'Сумма инвойса не может быть меньше суммы ожидающих платежей:',
            $update
        );
        $this->assertStringContainsString(
            "'lines.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01']",
            $controller
        );
    }

    public function test_web_pending_payment_store_delegates_to_canonical_action(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/PaymentController.php'));
        $store = $this->methodSource($source, 'public function store(', 'public function confirm(');
        $confirm = $this->methodSource($source, 'public function confirm(', 'public function cancel(');

        $this->assertStringContainsString('CreatePendingPayment', $source);
        $this->assertStringContainsString('$this->createPendingPayment->execute($invoice', $store);
        $this->assertStringNotContainsString('paymentAvailabilityService', $store);
        $this->assertStringNotContainsString('lockForUpdate()', $store);
        $this->assertStringNotContainsString('Payment::query()->create(', $store);
        $this->assertStringNotContainsString('paymentAvailabilityService', $confirm);
    }

    private function methodSource(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition);

        $this->assertNotFalse($startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
