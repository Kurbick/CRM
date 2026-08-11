<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceController;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiInvoiceIntegrityTest extends AuthorizationTestCase
{
    private const COMPACT_KEYS = [
        'id',
        'company_id',
        'contract_id',
        'invoice_number',
        'issue_date',
        'due_date',
        'status',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'is_overdue',
        'created_at',
        'updated_at',
    ];

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

    public function test_index_is_parent_scoped_compact_stable_and_uses_constant_queries(): void
    {
        $company = $this->company('API-INVOICE-INDEX-COMPANY');
        $otherCompany = $this->company('API-INVOICE-INDEX-OTHER-COMPANY');
        $first = $this->invoiceFixture($company, 'API-INVOICE-INDEX-FIRST', '100.00');
        $second = $this->invoiceFixture($company, 'API-INVOICE-INDEX-SECOND', '10.00');
        $other = $this->invoiceFixture($otherCompany, 'API-INVOICE-INDEX-OTHER', '500.00');
        $this->payment($first, 'pending', 'API-INVOICE-INDEX-PENDING-PAYMENT');
        $this->payment($first, 'confirmed', 'API-INVOICE-INDEX-CONFIRMED-PAYMENT');
        $this->payment($second, 'confirmed', 'API-INVOICE-INDEX-OVERPAYMENT');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.invoices.index', $company)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json('data');
        $this->assertSame(2, $capture['result']->json('meta.total'));
        $this->assertSame([$first->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[0]));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[1]));
        $this->assertSame('100.00', $payload[0]['total_amount']);
        $this->assertSame('25.00', $payload[0]['paid_amount']);
        $this->assertSame('75.00', $payload[0]['remaining_amount']);
        $this->assertSame('25.00', $payload[1]['paid_amount']);
        $this->assertSame('0.00', $payload[1]['remaining_amount']);
        $this->assertSame('2026-08-01', $payload[0]['issue_date']);
        $this->assertSame('2099-08-31', $payload[0]['due_date']);
        $this->assertIsString($payload[0]['created_at']);
        $this->assertIsString($payload[0]['updated_at']);
        $this->assertEqualsCanonicalizing(
            ['companies', 'invoices', 'payments'],
            DomainQueryRecorder::tables($capture['records'])
        );
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));

        foreach ([
            'API-INVOICE-INDEX-PENDING-PAYMENT',
            'API-INVOICE-INDEX-CONFIRMED-PAYMENT',
            'API-INVOICE-INDEX-OVERPAYMENT',
            $first->seller_iban,
            $first->comment,
            $first->lines()->firstOrFail()->description,
            $other->invoice_number,
        ] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_index_query_count_does_not_depend_on_invoice_count(): void
    {
        $singleCompany = $this->company('API-INVOICE-QUERY-SINGLE');
        $manyCompany = $this->company('API-INVOICE-QUERY-MANY');
        $this->invoiceFixture($singleCompany, 'API-INVOICE-QUERY-SINGLE-1');
        foreach (range(1, 5) as $index) {
            $invoice = $this->invoiceFixture($manyCompany, "API-INVOICE-QUERY-MANY-{$index}");
            $this->payment($invoice, 'confirmed', "API-INVOICE-QUERY-PAYMENT-{$index}");
        }
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $single = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.invoices.index', $singleCompany)),
        );
        $many = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.invoices.index', $manyCompany)),
        );

        $single['result']->assertOk();
        $many['result']->assertOk();
        $this->assertSame(3, DomainQueryRecorder::count($single['records']));
        $this->assertSame(3, DomainQueryRecorder::count($many['records']));
    }

    public function test_index_paginates_with_parent_scoped_totals_and_normalized_size(): void
    {
        $company = $this->company('API-INVOICE-PAGED-COMPANY');
        $otherCompany = $this->company('API-INVOICE-PAGED-OTHER');
        foreach (range(1, 26) as $index) {
            $this->invoiceFixture($company, "API-INVOICE-PAGED-{$index}");
        }
        $this->invoiceFixture($otherCompany, 'API-INVOICE-PAGED-FOREIGN');
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $response = $this->getJson(route('api.companies.invoices.index', $company).'?page=2&per_page=10');
        $response->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->assertSame('API-INVOICE-PAGED-11', $response->json('data.0.invoice_number'));
        $this->assertSame('API-INVOICE-PAGED-20', $response->json('data.9.invoice_number'));

        $normalized = $this->getJson(route('api.companies.invoices.index', $company).'?page=abc&per_page=-1');
        $normalized->assertOk()->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 26);
    }

    public function test_show_has_closed_projection_safe_summaries_lines_and_payment_aggregate(): void
    {
        [$invoice, $allowedSnapshots, $forbiddenMarkers] = $this->disclosureInvoice();
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.show', $invoice)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame(self::DETAIL_KEYS, array_keys($payload));
        $this->assertSame(['id', 'name', 'short_name'], array_keys($payload['company']));
        $this->assertSame(['id', 'company_id', 'contract_number'], array_keys($payload['contract']));
        $this->assertSame(
            $invoice->lines()->orderBy('id')->pluck('id')->all(),
            array_column($payload['lines'], 'id')
        );
        foreach ($payload['lines'] as $line) {
            $this->assertSame(self::LINE_KEYS, array_keys($line));
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $line['amount']);
        }
        foreach ($allowedSnapshots as $key => $value) {
            $capture['result']->assertJsonPath($key, $value);
        }
        $capture['result']
            ->assertJsonPath('total_amount', '100.00')
            ->assertJsonPath('paid_amount', '25.00')
            ->assertJsonPath('remaining_amount', '75.00')
            ->assertJsonPath('issue_date', '2026-08-01')
            ->assertJsonPath('due_date', '2099-08-31')
            ->assertJsonMissingPath('payments');
        $this->assertEqualsCanonicalizing(
            ['invoices', 'companies', 'contracts', 'invoice_lines', 'payments'],
            DomainQueryRecorder::tables($capture['records'])
        );
        $this->assertSame(5, DomainQueryRecorder::count($capture['records']));

        foreach ($forbiddenMarkers as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
        foreach ([
            'invoice_id',
            'order_id',
            'subscription_id',
            'billing_occurrence_key',
            'payments',
            'allocations',
            'credit_balance_entries',
        ] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $capture['result']->getContent());
        }
    }

    public function test_show_supports_nullable_contract_without_extra_contract_query(): void
    {
        $company = $this->company('API-INVOICE-NULL-CONTRACT');
        $invoice = $company->invoices()->create([
            'contract_id' => null,
            'invoice_number' => 'API-INVOICE-NULL-CONTRACT',
            'issue_date' => '2026-08-01',
            'due_date' => '2099-08-31',
            'total_amount' => '50.00',
            'status' => 'draft',
        ]);
        $invoice->lines()->create(['description' => 'Manual line', 'amount' => '50.00']);
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.invoices.show', $invoice)),
        );

        $capture['result']->assertOk()->assertJsonPath('contract', null);
        $this->assertEqualsCanonicalizing(
            ['invoices', 'companies', 'invoice_lines', 'payments'],
            DomainQueryRecorder::tables($capture['records'])
        );
        $this->assertSame(4, DomainQueryRecorder::count($capture['records']));
    }

    public function test_explicit_projection_ignores_already_loaded_relation_graph(): void
    {
        [$invoice, , $forbiddenMarkers] = $this->disclosureInvoice('API-INVOICE-LOADED');
        $invoice->load([
            'company.contacts',
            'contract.orders',
            'contract.subscriptions',
            'lines.order',
            'lines.subscription',
            'payments.allocations',
            'payments.creditBalanceEntries',
        ]);
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        $response = app(InvoiceController::class)->show($invoice);
        $content = $response->getContent();

        $this->assertNotFalse($content);
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::DETAIL_KEYS, array_keys($payload));
        foreach ($forbiddenMarkers as $marker) {
            $this->assertStringNotContainsString((string) $marker, $content);
        }
        $this->assertArrayNotHasKey('payments', $payload);
        $this->assertArrayNotHasKey('contacts', $payload['company']);
        $this->assertArrayNotHasKey('orders', $payload['contract']);
        $this->assertArrayNotHasKey('subscriptions', $payload['contract']);
    }

    /**
     * @return array{Invoice, array<string, mixed>, list<string|int>}
     */
    private function disclosureInvoice(string $prefix = 'API-INVOICE-SHOW'): array
    {
        $company = $this->company($prefix.' COMPANY');
        $company->forceFill([
            'short_name' => $prefix.' SHORT',
            'bank_name' => $prefix.' COMPANY BANK SECRET',
            'iban' => 'AZ00'.$prefix.'IBANSECRET',
            'swift' => 'SWIFTAZ22SECRET',
            'comment' => $prefix.' COMPANY COMMENT SECRET',
        ])->save();
        $company->contacts()->create([
            'first_name' => $prefix.' CONTACT SECRET',
            'last_name' => 'Hidden',
        ]);
        $contract = $this->contract($company);
        $contract->update(['comment' => $prefix.' CONTRACT COMMENT SECRET']);
        $order = $this->subjectOrder($contract, ['title' => $prefix.' ORDER SECRET']);
        $subscription = $this->subjectSubscription($contract, ['title' => $prefix.' SUBSCRIPTION SECRET']);
        $invoice = $this->invoiceFixture($company, $prefix, contract: $contract);
        $invoice->forceFill([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ])->save();
        $invoice->lines()->delete();
        $invoice->lines()->create([
            'order_id' => $order->id,
            'description' => 'Allowed order line snapshot',
            'amount' => '40.00',
        ]);
        $subscriptionLine = $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Allowed subscription line snapshot',
            'amount' => '60.00',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'billing_occurrence_key' => $prefix.'-OCCURRENCE-SECRET',
        ]);
        $pending = $this->payment($invoice, 'pending', $prefix.' PENDING PAYMENT SECRET');
        $pending->forceFill(['payment_method' => 'card'])->saveQuietly();
        $confirmed = $this->payment($invoice, 'confirmed', $prefix.' CONFIRMED PAYMENT SECRET');
        $balance = $company->creditBalance()->create(['amount' => '98765.43']);
        $entry = $balance->entries()->create([
            'type' => 'applied',
            'amount' => '12.34',
            'payment_id' => $confirmed->id,
            'invoice_id' => $invoice->id,
            'description' => $prefix.' CREDIT ENTRY SECRET',
        ]);
        $allocation = PaymentAllocation::query()
            ->where('payment_id', $confirmed->id)
            ->orderBy('id')
            ->firstOrFail();
        $allocation->forceFill(['amount' => '23.45'])->save();

        return [
            $invoice,
            [
                'seller_name' => $invoice->seller_name,
                'seller_voen' => $invoice->seller_voen,
                'seller_bank_name' => $invoice->seller_bank_name,
                'seller_iban' => $invoice->seller_iban,
                'seller_bank_code' => $invoice->seller_bank_code,
                'seller_bank_voen' => $invoice->seller_bank_voen,
                'seller_swift' => $invoice->seller_swift,
                'payer_name' => $invoice->payer_name,
                'payer_voen' => $invoice->payer_voen,
                'contract_reference' => $invoice->contract_reference,
            ],
            [
                $company->bank_name,
                $company->iban,
                $company->swift,
                $company->comment,
                $company->contacts()->firstOrFail()->first_name,
                $contract->comment,
                $order->title,
                $subscription->title,
                $pending->comment,
                $confirmed->comment,
                $pending->payment_method,
                $entry->description,
                $entry->amount,
                '98765.43',
                $subscriptionLine->billing_occurrence_key,
                $allocation->amount,
            ],
        ];
    }

    private function invoiceFixture(
        Company $company,
        string $number,
        string $total = '100.00',
        ?Contract $contract = null
    ): Invoice {
        $contract ??= $this->contract($company);
        $invoice = $company->invoices()->create([
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2099-08-31',
            'total_amount' => $total,
            'status' => 'draft',
            'seller_name' => $number.' SELLER SNAPSHOT',
            'seller_voen' => 'SV-123456789',
            'seller_bank_name' => $number.' SELLER BANK SNAPSHOT',
            'seller_iban' => 'AZ00SNAPSHOT000000000000000000',
            'seller_bank_code' => 'BC123',
            'seller_bank_voen' => 'BV123456',
            'seller_swift' => 'SWIFTAZ22',
            'payer_name' => $number.' PAYER SNAPSHOT',
            'payer_voen' => 'PV123456',
            'contract_reference' => $number.' CONTRACT SNAPSHOT',
            'comment' => $number.' COMMENT',
        ]);
        $invoice->lines()->create([
            'description' => $number.' LINE SECRET',
            'amount' => $total,
        ]);

        return $invoice;
    }
}
