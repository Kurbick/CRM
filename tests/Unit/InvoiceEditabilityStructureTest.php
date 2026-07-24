<?php

namespace Tests\Unit;

use Tests\TestCase;

class InvoiceEditabilityStructureTest extends TestCase
{
    public function test_web_update_locks_invoice_then_rechecks_shared_editability(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $update = $this->methodSource($source, 'public function update(', 'public function issue(');

        $this->assertStringContainsString('DB::transaction(', $update);
        $this->assertStringContainsString('->lockForUpdate()', $update);
        $this->assertStringContainsString('$this->editabilityService->evaluate($lockedInvoice)', $update);
        $this->assertLessThan(
            strpos($update, '$this->editabilityService->evaluate($lockedInvoice)'),
            strpos($update, '->lockForUpdate()')
        );
        $this->assertStringNotContainsString("\$invoiceData['status']", $update);
        $this->assertStringContainsString('Нельзя удалить связанную позицию из уже выставленного инвойса.', $update);
    }

    public function test_api_update_uses_same_lock_and_does_not_validate_protected_fields(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));
        $request = file_get_contents(app_path('Http/Requests/UpdateInvoiceRequest.php'));

        $this->assertStringContainsString('InvoiceEditabilityService', $controller);
        $this->assertStringContainsString('->lockForUpdate()', $controller);
        $this->assertStringContainsString('$this->editabilityService->evaluate($lockedInvoice)', $controller);

        foreach (['status', 'total_amount', 'seller_name', 'payer_name'] as $protectedField) {
            $this->assertStringNotContainsString("'{$protectedField}'", $request);
        }
    }

    public function test_issued_is_absent_from_web_filter_whitelist(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $index = $this->methodSource($source, 'public function index(', 'public function create(');

        $this->assertStringContainsString("'draft',\n            'partially_paid',", $index);
        $this->assertStringNotContainsString("'issued',", $index);
        $this->assertStringContainsString('unset($paginationParameters[\'status\'])', $index);
    }

    public function test_invoice_update_checks_pending_total_under_invoice_lock_before_line_mutations(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/InvoiceController.php'));
        $update = $this->methodSource($source, 'public function update(', 'public function issue(');

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
            "'lines.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01']",
            $update
        );
    }

    public function test_new_payment_recalculates_availability_after_invoice_lock_only_for_store(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/PaymentController.php'));
        $store = $this->methodSource($source, 'public function store(', 'public function confirm(');
        $confirm = $this->methodSource($source, 'public function confirm(', 'public function cancel(');

        $lockPosition = strpos($store, '->lockForUpdate()');
        $availabilityPosition = strpos(
            $store,
            '$this->paymentAvailabilityService->evaluate($lockedInvoice)'
        );
        $createPosition = strpos($store, '$payment = Payment::query()->create(');

        $this->assertLessThan($availabilityPosition, $lockPosition);
        $this->assertLessThan($createPosition, $availabilityPosition);
        $this->assertStringContainsString("\$paymentAvailability['pending_minor'] > 0", $store);
        $this->assertStringContainsString('Сумма платежа не может превышать остаток', $store);
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
