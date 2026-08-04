<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiPaymentIntegrityTest extends AuthorizationTestCase
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

    public function test_index_is_parent_scoped_compact_stable_and_disclosure_safe(): void
    {
        $fixture = $this->disclosureFixture('API-PAYMENT-INDEX');
        $foreign = $this->paymentRecord(
            $this->invoiceFor($this->company('API-PAYMENT-FOREIGN-COMPANY'), 'API-PAYMENT-FOREIGN-INVOICE'),
            '50.00',
            'API-PAYMENT-FOREIGN-COMMENT',
        );
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(
                route('api.invoices.payments.index', $fixture['invoice'])
                .'?invoice_id='.$foreign->invoice_id
            ),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame(
            [$fixture['payment']->id, $fixture['other_payment']->id],
            array_column($payload, 'id')
        );
        foreach ($payload as $payment) {
            $this->assertSame(self::PAYMENT_KEYS, array_keys($payment));
            $this->assertSame($fixture['invoice']->id, $payment['invoice_id']);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $payment['amount']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payment['payment_date']);
            $this->assertIsString($payment['created_at']);
            $this->assertIsString($payment['updated_at']);
        }
        $capture['result']
            ->assertJsonPath('0.amount', '0.01')
            ->assertJsonPath('0.payment_date', '2026-08-01')
            ->assertJsonPath('0.status', 'cancelled')
            ->assertJsonPath('0.comment', $fixture['payment']->comment)
            ->assertJsonPath('1.amount', '-25.00')
            ->assertDontSee($foreign->comment);
        $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));

        foreach ($fixture['forbidden_markers'] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
        foreach ($this->forbiddenKeys() as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $capture['result']->getContent());
        }
    }

    public function test_index_query_count_is_constant_for_one_or_many_payments(): void
    {
        $single = $this->invoiceFor($this->company('API-PAYMENT-QUERY-SINGLE'), 'API-PAYMENT-QUERY-SINGLE');
        $many = $this->invoiceFor($this->company('API-PAYMENT-QUERY-MANY'), 'API-PAYMENT-QUERY-MANY');
        $this->paymentRecord($single, '10.00', 'API-PAYMENT-QUERY-SINGLE');
        foreach (range(1, 6) as $index) {
            $this->paymentRecord($many, "{$index}.00", "API-PAYMENT-QUERY-MANY-{$index}");
        }
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $singleCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.payments.index', $single)),
        );
        $manyCapture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.payments.index', $many)),
        );

        $singleCapture['result']->assertOk()->assertJsonCount(1);
        $manyCapture['result']->assertOk()->assertJsonCount(6);
        $this->assertSame(2, DomainQueryRecorder::count($singleCapture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($manyCapture['records']));
        $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($singleCapture['records']));
        $this->assertSame(['invoices', 'payments'], DomainQueryRecorder::tables($manyCapture['records']));
    }

    public function test_show_has_exact_projection_and_only_binding_query(): void
    {
        $fixture = $this->disclosureFixture('API-PAYMENT-SHOW');
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.payments.show', $fixture['payment'])),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame(self::PAYMENT_KEYS, array_keys($payload));
        $capture['result']
            ->assertJsonPath('id', $fixture['payment']->id)
            ->assertJsonPath('invoice_id', $fixture['invoice']->id)
            ->assertJsonPath('amount', '0.01')
            ->assertJsonPath('payment_date', '2026-08-01')
            ->assertJsonPath('payment_method', 'card')
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('comment', $fixture['payment']->comment)
            ->assertJsonPath('created_at', $fixture['payment']->created_at?->toJSON())
            ->assertJsonPath('updated_at', $fixture['payment']->updated_at?->toJSON());
        $this->assertSame(['payments'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(1, DomainQueryRecorder::count($capture['records']));

        foreach ($fixture['forbidden_markers'] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
        foreach ($this->forbiddenKeys() as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        $this->getJson(route('api.payments.show', ['payment' => 1_000_000]))
            ->assertNotFound();
    }

    public function test_explicit_projection_ignores_already_loaded_relation_graph(): void
    {
        $fixture = $this->disclosureFixture('API-PAYMENT-LOADED');
        $payment = $fixture['payment'];
        $payment->load([
            'invoice.company.contacts',
            'invoice.lines.order',
            'company.creditBalance.entries',
            'allocations.invoiceLine.subscription',
            'creditBalanceEntries.creditBalance',
        ]);
        $this->actingAsPermissions([PermissionName::PaymentsView->value]);

        $response = app(PaymentController::class)->show($payment);
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::PAYMENT_KEYS, array_keys($payload));
        foreach ($fixture['forbidden_markers'] as $marker) {
            $this->assertStringNotContainsString((string) $marker, $content);
        }
        foreach ($this->forbiddenKeys() as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    /**
     * @return array{
     *     invoice: Invoice,
     *     payment: Payment,
     *     other_payment: Payment,
     *     forbidden_markers: list<string>
     * }
     */
    private function disclosureFixture(string $prefix): array
    {
        $company = $this->company($prefix.' COMPANY');
        $markerSuffix = substr(sha1($prefix), 0, 12);
        $company->forceFill([
            'bank_name' => $prefix.' COMPANY BANK SECRET',
            'iban' => 'AZ00'.$prefix.'IBANSECRET',
            'bank_code' => 'BC-'.$markerSuffix,
            'bank_voen' => 'BV-'.$markerSuffix,
            'swift' => 'SW-'.$markerSuffix,
            'comment' => $prefix.' COMPANY COMMENT SECRET',
        ])->save();
        $contact = $company->contacts()->create([
            'first_name' => $prefix.' CONTACT SECRET',
            'last_name' => 'Hidden',
        ]);
        $contract = $this->contract($company);
        $contract->forceFill(['comment' => $prefix.' CONTRACT COMMENT SECRET'])->save();
        $order = $this->subjectOrder($contract, ['title' => $prefix.' ORDER SOURCE SECRET']);
        $subscription = $this->subjectSubscription($contract, ['title' => $prefix.' SUBSCRIPTION SOURCE SECRET']);
        $invoice = $company->invoices()->create([
            'contract_id' => $contract->id,
            'invoice_number' => $prefix.'-INVOICE',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '200.00',
            'status' => 'issued',
            'seller_name' => $prefix.' SELLER SECRET',
            'seller_voen' => 'SV-'.$markerSuffix,
            'payer_name' => $prefix.' PAYER SECRET',
            'payer_voen' => 'PV-'.$markerSuffix,
            'contract_reference' => $prefix.' CONTRACT REFERENCE SECRET',
            'comment' => $prefix.' INVOICE COMMENT SECRET',
        ]);
        $orderLine = $invoice->lines()->create([
            'order_id' => $order->id,
            'description' => $prefix.' ORDER LINE SECRET',
            'amount' => '100.00',
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => $prefix.' SUBSCRIPTION LINE SECRET',
            'amount' => '100.00',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'billing_occurrence_key' => $prefix.'-OCCURRENCE-SECRET',
        ]);
        $payment = $this->paymentRecord(
            $invoice,
            '0.01',
            $prefix.' ALLOWED PAYMENT COMMENT',
            status: 'cancelled',
            method: 'card',
        );
        $payment->forceFill([
            'cancelled_at' => '2026-08-02 12:00:00',
            'cancel_reason' => $prefix.' CANCEL REASON SECRET',
        ])->saveQuietly();
        $otherPayment = $this->paymentRecord(
            $invoice,
            '-25.00',
            $prefix.' OTHER PAYMENT COMMENT',
            status: 'confirmed',
        );
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $otherPayment->id,
            'invoice_line_id' => $orderLine->id,
            'amount' => '17.89',
        ]);
        $balance = $company->creditBalance()->create(['amount' => '98765.43']);
        $entry = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '12.34',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'description' => $prefix.' CREDIT ENTRY SECRET',
        ]);

        return [
            'invoice' => $invoice,
            'payment' => $payment->fresh(),
            'other_payment' => $otherPayment,
            'forbidden_markers' => [
                $company->bank_name,
                $company->iban,
                $company->bank_code,
                $company->bank_voen,
                $company->swift,
                $company->comment,
                $contact->first_name,
                $contract->comment,
                $invoice->seller_name,
                $invoice->seller_voen,
                $invoice->payer_name,
                $invoice->payer_voen,
                $invoice->contract_reference,
                $invoice->comment,
                $orderLine->description,
                $order->title,
                $subscription->title,
                $payment->cancel_reason,
                $entry->description,
                '98765.43',
                $allocation->amount,
                $prefix.'-OCCURRENCE-SECRET',
            ],
        ];
    }

    private function invoiceFor(Company $company, string $number): Invoice
    {
        $contract = $this->contract($company);

        return $company->invoices()->create([
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'issued',
        ]);
    }

    private function paymentRecord(
        Invoice $invoice,
        string $amount,
        string $comment,
        string $status = 'pending',
        string $method = 'transfer',
    ): Payment {
        $id = Payment::query()->insertGetId([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-08-01',
            'amount' => $amount,
            'payment_method' => $method,
            'status' => $status,
            'comment' => $comment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Payment::query()->findOrFail($id);
    }

    /** @return list<string> */
    private function forbiddenKeys(): array
    {
        return [
            'company_id',
            'cancelled_at',
            'cancel_reason',
            'invoice',
            'company',
            'allocations',
            'credit_balance',
            'credit_balance_entries',
        ];
    }
}
