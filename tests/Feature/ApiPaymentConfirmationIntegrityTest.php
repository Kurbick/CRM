<?php

namespace Tests\Feature;

use App\Models\CreditBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\InvoicePaymentAllocationWriter;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mockery;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentConfirmationIntegrityTest extends AuthorizationTestCase
{
    private const PAYMENT_KEYS = [
        'id',
        'invoice_id',
        'amount',
        'payment_date',
        'payment_method',
        'status',
        'comment',
        'created_at',
        'updated_at',
    ];

    public function test_issued_partially_paid_and_paid_invoices_use_the_shared_state_matrix(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $issued = $this->invoiceFixture('issued', ['100.00']);
        $issuedPayment = $this->rawPayment($issued, 'pending', '40.00');
        $this->postJson(route('api.payments.confirm', $issuedPayment))
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->assertSame('partially_paid', $issued->fresh()->status);

        $partial = $this->invoiceFixture('partially_paid', ['100.00']);
        $this->rawPayment($partial, 'confirmed', '20.00');
        $partialPayment = $this->rawPayment($partial, 'pending', '80.00');
        $this->postJson(route('api.payments.confirm', $partialPayment))
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->assertSame('paid', $partial->fresh()->status);

        $paid = $this->invoiceFixture('paid', ['100.00']);
        $this->rawPayment($paid, 'confirmed', '100.00');
        $paidPayment = $this->rawPayment($paid, 'pending', '30.00');
        $this->postJson(route('api.payments.confirm', $paidPayment))
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $paidPayment->id,
            'invoice_id' => $paid->id,
            'amount' => '30.00',
        ]);
    }

    public function test_empty_body_and_empty_json_object_are_accepted(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $withoutBody = $this->rawPayment(
            $this->invoiceFixture('issued', ['100.00']),
            'pending',
            '10.00',
        );
        $emptyObject = $this->rawPayment(
            $this->invoiceFixture('issued', ['100.00']),
            'pending',
            '10.00',
        );

        $this->postJson(route('api.payments.confirm', $withoutBody))->assertOk();
        $this->call(
            'POST',
            route('api.payments.confirm', $emptyObject),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{}',
        )->assertOk();

        $this->assertSame('confirmed', $withoutBody->fresh()->status);
        $this->assertSame('confirmed', $emptyObject->fresh()->status);
    }

    public function test_every_known_and_unknown_top_level_field_is_rejected_before_the_action(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '10.00');
        $fields = [
            'id', 'invoice_id', 'company_id', 'amount', 'payment_date', 'payment_method',
            'status', 'comment', 'cancelled_at', 'cancel_reason', 'confirmed_at', 'reference',
            'allocations', 'payment_allocations', 'credit_balance', 'credit_balance_id',
            'credit_balance_entry_id', 'source_payment_id', 'source_invoice_id',
            'created_at', 'updated_at', 'unknown_top_level_field',
        ];

        foreach ($fields as $field) {
            $capture = (new DomainQueryRecorder)->capture(fn () => $this->postJson(
                route('api.payments.confirm', $payment),
                [$field => 'forbidden-value'],
            ));

            $capture['result']
                ->assertUnprocessable()
                ->assertJsonValidationErrors('request');
            $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
            $this->assertSame(1, DomainQueryRecorder::count($capture['records']));
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_success_response_is_the_exact_disclosure_safe_payment_projection(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '1.20');

        $response = $this->postJson(route('api.payments.confirm', $payment));

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame(self::PAYMENT_KEYS, array_keys($payload));
        $response
            ->assertJsonPath('id', $payment->id)
            ->assertJsonPath('invoice_id', $invoice->id)
            ->assertJsonPath('amount', '1.20')
            ->assertJsonPath('payment_date', '2026-08-05')
            ->assertJsonPath('payment_method', 'transfer')
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('comment', 'API confirmation integrity');

        foreach ($this->forbiddenResponseKeys() as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_confirmed_cancelled_and_forbidden_invoice_states_return_business_422_without_mutation(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $cases = [
            [$this->invoiceFixture('issued', ['100.00']), 'confirmed'],
            [$this->invoiceFixture('issued', ['100.00']), 'cancelled'],
            [$this->invoiceFixture('draft', ['100.00']), 'pending'],
            [$this->invoiceFixture('cancelled', ['100.00']), 'pending'],
        ];

        foreach ($cases as [$invoice, $paymentStatus]) {
            $payment = $this->rawPayment($invoice, $paymentStatus, '10.00');
            $originalInvoiceStatus = $invoice->status;

            $response = $this->postJson(route('api.payments.confirm', $payment));
            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment');
            $this->assertSame($response->json('errors.payment.0'), $response->json('message'));

            $this->assertSame($paymentStatus, $payment->fresh()->status);
            $this->assertSame($originalInvoiceStatus, $invoice->fresh()->status);
        }

        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_stale_relationship_and_company_mismatches_return_422_without_side_effects(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        $relationshipInvoice = $this->invoiceFixture('issued', ['100.00']);
        $otherInvoice = $this->invoiceFixture('issued', ['100.00']);
        $relationshipPayment = $this->rawPayment($relationshipInvoice, 'pending', '10.00');
        $this->mutateAfterPolicy($relationshipPayment, ['invoice_id' => $otherInvoice->id]);
        $this->postJson(route('api.payments.confirm', $relationshipPayment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $companyInvoice = $this->invoiceFixture('issued', ['100.00']);
        $foreignCompanyInvoice = $this->invoiceFixture('issued', ['100.00']);
        $companyPayment = $this->rawPayment($companyInvoice, 'pending', '10.00');
        $this->mutateAfterPolicy($companyPayment, ['company_id' => $foreignCompanyInvoice->company_id]);
        $this->postJson(route('api.payments.confirm', $companyPayment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame('pending', $relationshipPayment->fresh()->status);
        $this->assertSame('pending', $companyPayment->fresh()->status);
        $this->assertSame('issued', $relationshipInvoice->fresh()->status);
        $this->assertSame('issued', $companyInvoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_persisted_minor_unit_boundaries_and_legacy_non_positive_amounts(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);

        foreach ([
            ['0.01', '0.01'],
            ['1.20', '1.20'],
            ['99999999.99', '99999999.99'],
        ] as [$amount, $total]) {
            $invoice = $this->invoiceFixture('issued', [$total]);
            $payment = $this->rawPayment($invoice, 'pending', $amount);
            $this->postJson(route('api.payments.confirm', $payment))
                ->assertOk()
                ->assertJsonPath('amount', $amount);
            $this->assertSame('paid', $invoice->fresh()->status);
        }

        foreach (['0.00', '-0.01'] as $amount) {
            $invoice = $this->invoiceFixture('issued', ['100.00']);
            $payment = $this->rawPayment($invoice, 'pending', $amount);
            $this->postJson(route('api.payments.confirm', $payment))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment');
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('issued', $invoice->fresh()->status);
        }
    }

    public function test_issued_invoice_overpayment_creates_credit_without_response_disclosure(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '130.00');

        $response = $this->postJson(route('api.payments.confirm', $payment));

        $response->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame(self::PAYMENT_KEYS, array_keys($response->json()));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('100.00', PaymentAllocation::query()->sum('amount'));
        $this->assertDatabaseHas('credit_balances', [
            'company_id' => $invoice->company_id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
        ]);
    }

    public function test_paid_invoice_additional_payment_creates_only_new_credit_without_duplicate_allocation(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('paid', ['100.00']);
        $existing = $this->rawPayment($invoice, 'confirmed', '100.00');
        $payment = $this->rawPayment($invoice, 'pending', '30.00');

        $this->postJson(route('api.payments.confirm', $payment))->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $existing->id,
            'amount' => '100.00',
        ]);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('credit_balance_entries', [
            'type' => 'top_up',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => '30.00',
        ]);
        $this->assertDatabaseCount('credit_balance_entries', 1);
    }

    public function test_repeated_confirmation_returns_422_without_duplicate_credit_or_allocations(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '130.00');

        $this->postJson(route('api.payments.confirm', $payment))->assertOk();
        $this->postJson(route('api.payments.confirm', $payment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balance_entries', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame('30.00', CreditBalance::query()->sole()->getRawOriginal('amount'));
    }

    public function test_unexpected_writer_failure_propagates_and_rolls_back_the_full_transaction(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'pending', '130.00');
        $writer = Mockery::mock(InvoicePaymentAllocationWriter::class);
        $writer->shouldReceive('synchronize')->once()->andThrow(new RuntimeException('api-confirm-writer-failure'));
        $this->app->instance(InvoicePaymentAllocationWriter::class, $writer);
        $this->withoutExceptionHandling();

        try {
            $this->postJson(route('api.payments.confirm', $payment));
            $this->fail('Unexpected writer failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('api-confirm-writer-failure', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(InvoicePaymentAllocationWriter::class);
        }

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_business_conflict_uses_only_binding_and_invoice_payment_locks(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $invoice = $this->invoiceFixture('issued', ['100.00']);
        $payment = $this->rawPayment($invoice, 'confirmed', '10.00');

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(route('api.payments.confirm', $payment)),
        );

        $capture['result']->assertUnprocessable();
        $this->assertSame(['payments', 'invoices'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));
    }

    public function test_success_queries_are_bounded_and_independent_of_invoice_line_count(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $counts = [];

        foreach ([1, 6] as $lineCount) {
            $invoice = $this->invoiceFixture('issued', array_fill(0, $lineCount, '10.00'));
            $payment = $this->rawPayment($invoice, 'pending', '5.00');
            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->postJson(route('api.payments.confirm', $payment)),
            );

            $capture['result']->assertOk();
            $tables = DomainQueryRecorder::tables($capture['records']);
            $this->assertContains('payments', $tables);
            $this->assertContains('invoices', $tables);
            $this->assertContains('invoice_lines', $tables);
            $this->assertContains('payment_allocations', $tables);
            $this->assertNotContains('companies', $tables);
            $this->assertNotContains('credit_balances', $tables);
            $counts[] = DomainQueryRecorder::count($capture['records']);
            $this->assertLessThanOrEqual(12, end($counts));
        }

        $this->assertSame([11, 11], $counts);
    }

    public function test_overpayment_credit_queries_are_bounded_for_one_and_six_lines(): void
    {
        $this->actingAsPermissions([PermissionName::PaymentsConfirm->value]);
        $counts = [];
        $creditCounts = [];

        foreach ([1, 6] as $lineCount) {
            $invoice = $this->invoiceFixture('issued', array_fill(0, $lineCount, '10.00'));
            $payment = $this->rawPayment($invoice, 'pending', '100.00');
            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->postJson(route('api.payments.confirm', $payment)),
            );

            $capture['result']->assertOk();
            $tables = DomainQueryRecorder::tables($capture['records']);
            $this->assertContains('credit_balances', $tables);
            $this->assertContains('credit_balance_entries', $tables);
            $this->assertNotContains('companies', $tables);
            $counts[] = DomainQueryRecorder::count($capture['records']);
            $this->assertLessThanOrEqual(22, end($counts));
            $creditCounts[] = count(array_filter(
                $capture['records'],
                static fn (array $record): bool => array_intersect(
                    ['credit_balances', 'credit_balance_entries'],
                    $record['tables'],
                ) !== [],
            ));
        }

        $this->assertSame([17, 22], $counts);
        $this->assertSame([6, 6], $creditCounts);
    }

    /** @param list<string> $lineAmounts */
    private function invoiceFixture(string $status, array $lineAmounts): Invoice
    {
        $company = $this->company('API confirmation '.uniqid());
        $contract = $this->contract($company);
        $total = array_sum(array_map(
            static fn (string $amount): int => (int) str_replace('.', '', $amount),
            $lineAmounts,
        ));
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'API-CONFIRM-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => sprintf('%d.%02d', intdiv($total, 100), $total % 100),
            'status' => $status,
        ]);

        foreach ($lineAmounts as $index => $amount) {
            $invoice->lines()->create([
                'description' => 'API confirmation line '.($index + 1),
                'amount' => $amount,
            ]);
        }

        return $invoice;
    }

    private function rawPayment(Invoice $invoice, string $status, string $amount): Payment
    {
        $id = DB::table('payments')->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-05',
            'amount' => $amount,
            'payment_method' => 'transfer',
            'status' => $status,
            'comment' => 'API confirmation integrity',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Payment::query()->findOrFail($id);
    }

    /** @param array<string, int> $attributes */
    private function mutateAfterPolicy(Payment $payment, array $attributes): void
    {
        $mutated = false;
        Gate::after(function ($user, string $ability, $result, array $arguments) use (
            $payment,
            $attributes,
            &$mutated,
        ): void {
            if (
                ! $mutated
                && $ability === 'confirm'
                && ($arguments[0] ?? null) instanceof Payment
                && $arguments[0]->is($payment)
            ) {
                Payment::query()->whereKey($payment->id)->update($attributes);
                $mutated = true;
            }
        });
    }

    /** @return list<string> */
    private function forbiddenResponseKeys(): array
    {
        return [
            'company_id', 'invoice', 'company', 'allocations', 'payment_allocations',
            'credit_balance', 'credit_balance_entries', 'source_payment_id', 'source_invoice_id',
            'cancelled_at', 'cancel_reason', 'invoice_status', 'credit_created', 'overpayment',
            'allocation_summary', 'remaining_amount',
        ];
    }
}
