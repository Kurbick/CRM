<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceDueDateCalculator;
use App\Support\Access\PermissionName;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiInvoiceUpdateIntegrityTest extends AuthorizationTestCase
{
    private const DETAIL_KEYS = [
        'id',
        'company_id',
        'contract_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'status',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'is_overdue',
        'comment',
        'seller_name',
        'seller_voen',
        'seller_bank_name',
        'seller_iban',
        'seller_bank_code',
        'seller_bank_voen',
        'seller_swift',
        'payer_name',
        'payer_voen',
        'contract_reference',
        'created_at',
        'updated_at',
        'company',
        'contract',
        'lines',
    ];

    private const LINE_KEYS = [
        'id',
        'description',
        'amount',
        'period_start',
        'period_end',
    ];

    public function test_manual_draft_supports_patch_metadata_and_merged_date_invariants(): void
    {
        $invoice = $this->manualInvoice('API-UPDATE-MANUAL');
        $other = $this->manualInvoice('API-UPDATE-MANUAL-OTHER');
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Updated comment'])
            ->assertOk()
            ->assertJsonPath('comment', 'Updated comment');
        $this->patchJson(route('api.invoices.update', $invoice), [
            'invoice_number' => 'API-UPDATE-MANUAL-RENAMED',
        ])->assertOk();
        $this->patchJson(route('api.invoices.update', $invoice), [
            'due_date' => '2026-09-15',
        ])->assertOk();
        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-30',
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame('API-UPDATE-MANUAL-RENAMED', $invoice->invoice_number);
        $this->assertSame('2026-09-01', $invoice->issue_date);
        $this->assertSame('2026-09-30', $invoice->due_date);
        $this->assertSame('Updated comment', $invoice->comment);

        $beforeRejectedUpdate = $invoice->only(['issue_date', 'due_date', 'comment']);
        $this->patchJson(route('api.invoices.update', $invoice), [
            'due_date' => '2026-08-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-09-01T00:00:00+04:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('issue_date');

        $this->assertSame($beforeRejectedUpdate, $invoice->fresh()->only(array_keys($beforeRejectedUpdate)));
        $this->assertSame('API-UPDATE-MANUAL-OTHER', $other->fresh()->invoice_number);
    }

    public function test_manual_draft_rejects_null_due_date_without_mutation(): void
    {
        $invoice = $this->manualInvoice('API-UPDATE-MANUAL-NULL');
        $originalDueDate = $invoice->due_date;
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), ['due_date' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->assertSame($originalDueDate, $invoice->fresh()->due_date);
    }

    public function test_order_sourced_draft_rejects_due_date_and_recalculates_after_issue_date_change(): void
    {
        ['invoice' => $invoice] = $this->sourcedInvoice(
            'API-UPDATE-ORDER-SOURCE',
            orderTerms: 14,
            subscriptionTerms: null,
        );
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Sourced comment'])
            ->assertOk();
        $this->patchJson(route('api.invoices.update', $invoice), [
            'invoice_number' => 'API-UPDATE-ORDER-SOURCE-RENAMED',
        ])->assertOk();

        $this->patchJson(route('api.invoices.update', $invoice), [
            'due_date' => $invoice->due_date,
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-09-01',
        ])->assertOk()->assertJsonPath('due_date', '2026-09-15');

        $invoice->refresh();
        $this->assertSame('2026-09-01', $invoice->issue_date);
        $this->assertSame('2026-09-15', $invoice->due_date);
        $this->assertSame('Sourced comment', $invoice->comment);
    }

    public function test_sourced_due_date_uses_minimum_order_and_subscription_terms(): void
    {
        ['invoice' => $invoice] = $this->sourcedInvoice(
            'API-UPDATE-MIXED-SOURCE',
            orderTerms: 30,
            subscriptionTerms: 10,
        );
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), [
            'issue_date' => '2026-09-01',
        ])->assertOk()->assertJsonPath('due_date', '2026-09-11');

        $this->assertSame('2026-09-11', $invoice->fresh()->due_date);
    }

    public function test_issued_invoice_allows_only_comment_even_when_value_is_unchanged(): void
    {
        $invoice = $this->manualInvoice('API-UPDATE-ISSUED', 'issued');
        $line = $invoice->lines()->sole();
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Issued comment'])
            ->assertOk()
            ->assertJsonPath('comment', 'Issued comment');

        foreach ([
            ['invoice_number' => $invoice->invoice_number],
            ['issue_date' => $invoice->issue_date],
            ['due_date' => $invoice->due_date],
            ['lines' => [[
                'id' => $line->id,
                'description' => $line->description,
                'amount' => $line->amount,
            ]]],
        ] as $payload) {
            $field = array_key_first($payload);
            $this->patchJson(route('api.invoices.update', $invoice), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $invoice->refresh();
        $this->assertSame('API-UPDATE-ISSUED', $invoice->invoice_number);
        $this->assertSame('2026-08-01', $invoice->issue_date);
        $this->assertSame('2026-08-31', $invoice->due_date);
        $this->assertSame('Issued comment', $invoice->comment);
        $this->assertSame('Manual API update line', $line->fresh()->description);
    }

    public function test_non_editable_states_confirmed_payment_and_administrator_are_blocked(): void
    {
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        foreach (['partially_paid', 'paid', 'cancelled'] as $status) {
            $invoice = $this->manualInvoice('API-UPDATE-'.strtoupper($status), $status);
            $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Blocked'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('invoice');
            $this->assertNull($invoice->fresh()->comment);
        }

        $confirmedInvoice = $this->manualInvoice('API-UPDATE-CONFIRMED', 'issued');
        $this->payment($confirmedInvoice, 'confirmed', 'API-UPDATE-CONFIRMED-PAYMENT');
        $this->patchJson(route('api.invoices.update', $confirmedInvoice), ['comment' => 'Blocked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $adminInvoice = $this->manualInvoice('API-UPDATE-ADMIN-BLOCKED', 'paid');
        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator, 'web');
        $this->patchJson(route('api.invoices.update', $adminInvoice), ['comment' => 'Admin blocked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
        $this->assertNull($adminInvoice->fresh()->comment);
    }

    public function test_pending_payment_allows_metadata_only_update_without_financial_mutation(): void
    {
        $invoice = $this->manualInvoice('API-UPDATE-PENDING', 'issued');
        $line = $invoice->lines()->sole();
        $payment = $this->payment($invoice, 'pending', 'API-UPDATE-PENDING-PAYMENT');
        $originalInvoice = $invoice->only(['total_amount', 'status']);
        $originalLine = $line->getAttributes();
        $originalPayment = $payment->getAttributes();
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);

        $this->patchJson(route('api.invoices.update', $invoice), ['comment' => 'Pending-safe comment'])
            ->assertOk()
            ->assertJsonPath('comment', 'Pending-safe comment');

        $this->assertSame($originalInvoice, $invoice->fresh()->only(array_keys($originalInvoice)));
        $this->assertSame($originalLine, $line->fresh()->getAttributes());
        $this->assertSame($originalPayment, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_prohibited_ownership_lifecycle_and_line_fields_fail_without_side_effects(): void
    {
        ['invoice' => $invoice, 'subscription' => $subscription] = $this->sourcedInvoice(
            'API-UPDATE-PROHIBITED',
            orderTerms: null,
            subscriptionTerms: 15,
        );
        $line = $invoice->lines()->sole();
        $payment = $this->payment($invoice, 'pending', 'API-UPDATE-PROHIBITED-PAYMENT');
        $otherCompany = $this->company('API-UPDATE-PROHIBITED-OTHER');
        $otherContract = $this->contract($otherCompany);
        $originalInvoice = $invoice->fresh()->getAttributes();
        $originalLine = $line->getAttributes();
        $originalPayment = $payment->getAttributes();
        $originalNextBillingDate = $subscription->next_billing_date->toDateString();
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);
        $payload = [
            'company_id' => $otherCompany->id,
            'contract_id' => $otherContract->id,
            'status' => 'cancelled',
            'total_amount' => '999.99',
            'period_start' => '2035-01-01',
            'period_end' => '2035-12-31',
            'seller_name' => 'FORGED SELLER',
            'seller_voen' => 'FORGED-SELLER-VOEN',
            'seller_bank_name' => 'FORGED SELLER BANK',
            'seller_iban' => 'FORGED-SELLER-IBAN',
            'seller_bank_code' => 'FORGED-SELLER-CODE',
            'seller_bank_voen' => 'FORGED-SELLER-BANK-VOEN',
            'seller_swift' => 'FORGED-SELLER-SWIFT',
            'payer_name' => 'FORGED PAYER',
            'payer_voen' => 'FORGED-PAYER-VOEN',
            'contract_reference' => 'FORGED-CONTRACT',
            'lines' => [[
                'id' => $line->id,
                'invoice_id' => $otherCompany->id,
                'description' => 'FORGED LINE',
                'amount' => '0.01',
                'order_id' => 1_000_000,
                'subscription_id' => 1_000_000,
                'period_start' => '2035-01-01',
                'period_end' => '2035-12-31',
                'billing_occurrence_key' => str_repeat('f', 64),
            ]],
            'id' => 1_000_000,
            'created_at' => '2035-01-01T00:00:00Z',
            'updated_at' => '2035-01-01T00:00:00Z',
            'paid_amount' => '999.99',
            'remaining_amount' => '0.00',
            'is_overdue' => true,
        ];

        $this->patchJson(route('api.invoices.update', $invoice), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($payload));

        $this->assertEquals($originalInvoice, $invoice->fresh()->getAttributes());
        $this->assertSame($originalLine, $line->fresh()->getAttributes());
        $this->assertSame($originalPayment, $payment->fresh()->getAttributes());
        $this->assertSame($originalNextBillingDate, $subscription->fresh()->next_billing_date->toDateString());
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_success_response_matches_safe_detail_projection_and_uses_bounded_queries(): void
    {
        ['invoice' => $invoice, 'order' => $order] = $this->sourcedInvoice(
            'API-UPDATE-SAFE-RESPONSE',
            orderTerms: 14,
            subscriptionTerms: null,
        );
        $invoice->company->update(['short_name' => 'Safe payer']);
        $payment = $this->payment($invoice, 'pending', 'PAYMENT-COMMENT-MUST-NOT-LEAK');
        for ($index = 0; $index < 4; $index++) {
            $invoice->lines()->create([
                'description' => "Safe manual line {$index}",
                'amount' => '10.00',
            ]);
            $this->payment($invoice, 'pending', "PENDING-PAYMENT-MARKER-{$index}");
        }
        $invoice->update(['total_amount' => '140.00']);
        $this->actingAsPermissions([
            PermissionName::InvoicesUpdate->value,
            PermissionName::InvoicesView->value,
        ]);

        $show = $this->getJson(route('api.invoices.show', $invoice))->assertOk();
        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->patchJson(route('api.invoices.update', $invoice), [
                'comment' => 'Safe response comment',
            ]),
        );
        $response = $capture['result']->assertOk();

        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $this->assertSame(array_keys($show->json()), array_keys($response->json()));
        $this->assertSame(self::LINE_KEYS, array_keys($response->json('lines.0')));
        $response
            ->assertJsonPath('company', [
                'id' => $invoice->company_id,
                'name' => $invoice->company->name,
                'short_name' => 'Safe payer',
            ])
            ->assertJsonPath('contract', [
                'id' => $invoice->contract_id,
                'company_id' => $invoice->company_id,
                'contract_number' => $invoice->contract->contract_number,
            ])
            ->assertJsonPath('total_amount', '140.00')
            ->assertJsonPath('paid_amount', '0.00')
            ->assertJsonPath('remaining_amount', '140.00')
            ->assertJsonPath('issue_date', '2026-08-01')
            ->assertJsonMissingPath('payments')
            ->assertJsonMissingPath('lines.0.invoice_id')
            ->assertJsonMissingPath('lines.0.order_id')
            ->assertJsonMissingPath('lines.0.subscription_id')
            ->assertJsonMissingPath('lines.0.billing_occurrence_key')
            ->assertDontSee($payment->comment)
            ->assertDontSee($order->title);

        $this->assertLessThanOrEqual(12, DomainQueryRecorder::count($capture['records']));
        $this->assertSame([], array_values(array_intersect(
            DomainQueryRecorder::tables($capture['records']),
            ['payment_allocations', 'credit_balances', 'credit_balance_entries', 'orders', 'subscriptions']
        )));
    }

    public function test_due_date_calculator_failure_rethrows_and_rolls_back_metadata(): void
    {
        ['invoice' => $invoice] = $this->sourcedInvoice(
            'API-UPDATE-ROLLBACK',
            orderTerms: 14,
            subscriptionTerms: null,
        );
        $line = $invoice->lines()->sole();
        $originalInvoice = $invoice->fresh()->getAttributes();
        $originalLine = $line->getAttributes();
        $this->actingAsPermissions([PermissionName::InvoicesUpdate->value]);
        $this->mock(InvoiceDueDateCalculator::class)
            ->shouldReceive('calculate')
            ->once()
            ->andThrow(new RuntimeException('BROKEN API UPDATE DUE DATE'));
        $this->withoutExceptionHandling();

        $thrown = null;
        try {
            $this->patchJson(route('api.invoices.update', $invoice), [
                'invoice_number' => 'API-UPDATE-ROLLBACK-CHANGED',
                'issue_date' => '2026-09-01',
                'comment' => 'Must roll back',
            ]);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('BROKEN API UPDATE DUE DATE', $thrown->getMessage());
        $this->assertEquals($originalInvoice, $invoice->fresh()->getAttributes());
        $this->assertSame($originalLine, $line->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    private function manualInvoice(string $number, string $status = 'draft'): Invoice
    {
        $company = $this->company($number.' Company');
        $contract = $this->contract($company);
        $invoice = $this->invoiceRecord($company, $contract, $number, $status);
        $invoice->lines()->create([
            'description' => 'Manual API update line',
            'amount' => '100.00',
        ]);

        return $invoice;
    }

    /**
     * @return array{invoice: Invoice, order: ?Order, subscription: ?Subscription}
     */
    private function sourcedInvoice(
        string $number,
        ?int $orderTerms,
        ?int $subscriptionTerms,
    ): array {
        $company = $this->company($number.' Company');
        $contract = $this->contract($company);
        $invoice = $this->invoiceRecord($company, $contract, $number);
        $order = null;
        $subscription = null;

        if ($orderTerms !== null) {
            $order = $this->subjectOrder($contract, ['payment_terms' => $orderTerms]);
            $invoice->lines()->create([
                'order_id' => $order->id,
                'description' => 'Safe order-backed line',
                'amount' => $subscriptionTerms === null ? '100.00' : '50.00',
            ]);
        }

        if ($subscriptionTerms !== null) {
            $subscription = $this->subjectSubscription($contract, [
                'payment_terms' => $subscriptionTerms,
            ]);
            $invoice->lines()->create([
                'subscription_id' => $subscription->id,
                'description' => 'Safe subscription-backed line',
                'amount' => $orderTerms === null ? '100.00' : '50.00',
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'billing_occurrence_key' => hash('sha256', $number),
            ]);
        }

        return compact('invoice', 'order', 'subscription');
    }

    private function invoiceRecord(
        Company $company,
        Contract $contract,
        string $number,
        string $status = 'draft',
    ): Invoice {
        return Invoice::query()->create([
            'company_id' => $company->id,
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => $status,
            'seller_name' => $number.' SELLER SNAPSHOT',
            'seller_voen' => 'SELLER-VOEN',
            'seller_bank_name' => $number.' SELLER BANK',
            'seller_iban' => 'AZ00SELLERSNAPSHOT',
            'seller_bank_code' => 'SELLER-CODE',
            'seller_bank_voen' => 'SELLER-BANK-VOEN',
            'seller_swift' => 'SELLER-SWIFT',
            'payer_name' => $company->name,
            'payer_voen' => 'PAYER-VOEN',
            'contract_reference' => $contract->contract_number,
        ]);
    }
}
