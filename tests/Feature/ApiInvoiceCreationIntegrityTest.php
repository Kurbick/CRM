<?php

namespace Tests\Feature;

use App\Actions\Invoices\CreateInvoice;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiInvoiceCreationIntegrityTest extends AuthorizationTestCase
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

    public function test_manual_invoice_uses_bound_parent_server_snapshots_total_and_safe_response(): void
    {
        $sellerSnapshot = $this->configureSeller('API CREATE SELLER');
        $company = $this->company('API CREATE PAYER');
        $company->update(['short_name' => 'Payer', 'voen' => 'PAYER-VOEN']);
        $otherCompany = $this->company('API CREATE OTHER');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $payload = $this->payload($contract, [[
            'description' => 'Manual line',
            'amount' => '10.05',
        ]], 'API-CREATE-MANUAL');
        $payload += [
            'company_id' => $otherCompany->id,
            'payer_name' => 'FORGED PAYER',
            'payer_voen' => 'FORGED VOEN',
            'contract_reference' => 'FORGED CONTRACT',
            'period_start' => '2020-01-01',
            'period_end' => '2020-12-31',
        ];

        $response = $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertCreated();

        $invoice = Invoice::query()->sole();
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response
            ->assertJsonPath('company', [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => $company->short_name,
            ])
            ->assertJsonPath('contract', [
                'id' => $contract->id,
                'company_id' => $company->id,
                'contract_number' => $contract->contract_number,
            ])
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_amount', '10.05')
            ->assertJsonPath('paid_amount', '0.00')
            ->assertJsonPath('remaining_amount', '10.05')
            ->assertJsonPath('payer_name', $company->name)
            ->assertJsonPath('payer_voen', $company->voen)
            ->assertJsonPath('contract_reference', $contract->contract_number)
            ->assertJsonMissingPath('payments')
            ->assertJsonMissingPath('lines.0.invoice_id')
            ->assertJsonMissingPath('lines.0.order_id')
            ->assertJsonMissingPath('lines.0.subscription_id')
            ->assertJsonMissingPath('lines.0.billing_occurrence_key');
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame($contract->id, $invoice->contract_id);
        $this->assertSame('10.05', $invoice->total_amount);
        $this->assertNull($invoice->period_start);
        $this->assertNull($invoice->period_end);
        $this->assertSellerSnapshot($invoice, $sellerSnapshot);
        foreach ($sellerSnapshot as $field => $value) {
            $response->assertJsonPath($field, $value);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
    }

    public function test_forged_seller_fields_are_rejected_before_any_mutation(): void
    {
        $company = $this->company('API CREATE FORGED SELLER');
        $contract = $this->contract($company);
        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2026-09-01',
        ]);
        $originalNextBillingDate = $subscription->next_billing_date->toDateString();
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $forged = [
            'seller_name' => 'FORGED SELLER NAME',
            'seller_voen' => 'FORGED-VOEN',
            'seller_bank_name' => 'FORGED BANK NAME',
            'seller_iban' => 'FORGED-IBAN',
            'seller_bank_code' => 'FORGED-CODE',
            'seller_bank_voen' => 'FORGED-BANK-VOEN',
            'seller_swift' => 'FORGED-SWIFT',
        ];

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload(
                $contract,
                [$this->subscriptionLine($subscription)],
                'API-CREATE-FORGED-SELLER'
            ) + $forged
        )->assertUnprocessable()->assertJsonValidationErrors(array_keys($forged));

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('credit_balances', 0);
        $this->assertDatabaseCount('credit_balance_entries', 0);
        $this->assertSame(
            $originalNextBillingDate,
            $subscription->fresh()->next_billing_date->toDateString()
        );
    }

    public function test_invoice_creation_uses_organization_instead_of_seller_config(): void
    {
        config([
            'invoice.seller.name' => '',
            'invoice.seller.voen' => '   ',
            'invoice.seller.bank_name' => null,
            'invoice.seller.iban' => '  AZ00SERVERIBAN  ',
            'invoice.seller.bank_code' => '  SERVER-CODE  ',
            'invoice.seller.bank_voen' => '  SERVER-BANK-VOEN  ',
            'invoice.seller.swift' => '  SERVER-SWIFT  ',
        ]);
        $company = $this->company('API CREATE NULL SELLER');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload($contract, [$this->manualLine()], 'API-CREATE-NULL-SELLER')
        )->assertCreated();

        $invoice = Invoice::query()->sole();
        $organization = Organization::current();
        $this->assertNotNull($organization);
        $this->assertSame($organization->name, $invoice->seller_name);
        $this->assertSame($organization->voen, $invoice->seller_voen);
        $this->assertSame($organization->bank_name, $invoice->seller_bank_name);
        $this->assertSame($organization->iban, $invoice->seller_iban);
        $this->assertSame($organization->bank_code, $invoice->seller_bank_code);
        $this->assertSame($organization->bank_voen, $invoice->seller_bank_voen);
        $this->assertSame($organization->swift, $invoice->seller_swift);
    }

    public function test_seller_snapshot_is_immutable_after_configuration_changes(): void
    {
        $snapshot = $this->configureSeller('API SELLER SNAPSHOT A');
        $company = $this->company('API CREATE IMMUTABLE SELLER');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload($contract, [$this->manualLine()], 'API-CREATE-IMMUTABLE-SELLER')
        )->assertCreated();

        $invoice = Invoice::query()->sole();
        $this->configureSeller('API SELLER SNAPSHOT B');

        $this->assertSellerSnapshot($invoice->fresh(), $snapshot);
    }

    public function test_contract_is_required_and_must_belong_to_bound_company(): void
    {
        $company = $this->company('API CREATE CONTRACT OWNER');
        $otherCompany = $this->company('API CREATE CONTRACT OTHER');
        $contract = $this->contract($company);
        $otherContract = $this->contract($otherCompany);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $missing = $this->payload($contract, [$this->manualLine()], 'API-CREATE-NO-CONTRACT');
        unset($missing['contract_id']);
        $this->postJson(route('api.companies.invoices.store', $company), $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_id');

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload($otherContract, [$this->manualLine()], 'API-CREATE-CROSS-CONTRACT')
        )->assertUnprocessable()->assertJsonValidationErrors('contract_id');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('contracts', ['id' => $otherContract->id, 'company_id' => $otherCompany->id]);
    }

    public function test_create_invoice_action_rejects_foreign_contract_without_writes(): void
    {
        $company = $this->company('ACTION CREATE COMPANY');
        $foreignContract = $this->contract($this->company('ACTION CREATE FOREIGN COMPANY'));

        try {
            app(CreateInvoice::class)->execute(
                $company,
                $foreignContract,
                [
                    'invoice_number' => 'ACTION-CREATE-FOREIGN-CONTRACT',
                    'issue_date' => '2026-08-01',
                    'due_date' => '2026-08-31',
                    'comment' => null,
                ],
                [[
                    'description' => 'Manual line',
                    'amount' => '10.00',
                ]],
            );
            $this->fail('A foreign contract was accepted by CreateInvoice.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_lines', 0);
        }
    }

    public function test_create_invoice_action_rejects_foreign_source_without_writes(): void
    {
        $company = $this->company('ACTION SOURCE COMPANY');
        $contract = $this->contract($company);
        $foreignOrder = $this->subjectOrder($this->contract($this->company('ACTION SOURCE FOREIGN')));

        try {
            app(CreateInvoice::class)->execute(
                $company,
                $contract,
                [
                    'invoice_number' => 'ACTION-CREATE-FOREIGN-SOURCE',
                    'issue_date' => '2026-08-01',
                    'due_date' => '2026-08-31',
                    'comment' => null,
                ],
                [[
                    'description' => 'Foreign order',
                    'amount' => '10.00',
                    'order_id' => $foreignOrder->id,
                ]],
            );
            $this->fail('A foreign source was accepted by CreateInvoice.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_lines', 0);
        }
    }

    public function test_order_source_is_scoped_and_controls_sourced_due_date(): void
    {
        $company = $this->company('API CREATE ORDER OWNER');
        $contract = $this->contract($company);
        $otherContract = $this->contract($this->company('API CREATE ORDER OTHER'));
        $order = $this->subjectOrder($contract, ['payment_terms' => 14]);
        $foreignOrder = $this->subjectOrder($otherContract);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $foreignPayload = $this->payload($contract, [$this->orderLine($foreignOrder)], 'API-CREATE-FOREIGN-ORDER');
        $this->postJson(route('api.companies.invoices.store', $company), $foreignPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.order_id');

        $unknownPayload = $this->payload($contract, [[
            'order_id' => 1_000_000,
            'description' => 'Unknown order',
            'amount' => '10.00',
        ]], 'API-CREATE-UNKNOWN-ORDER');
        $this->postJson(route('api.companies.invoices.store', $company), $unknownPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.order_id');

        $payload = $this->payload($contract, [$this->orderLine($order)], 'API-CREATE-ORDER');
        $payload['due_date'] = '2030-01-01';
        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertCreated()
            ->assertJsonPath('due_date', '2026-08-15');

        $invoice = Invoice::query()->sole();
        $this->assertSame('2026-08-15', $invoice->due_date);
        $this->assertSame($order->id, $invoice->lines()->sole()->order_id);
    }

    public function test_subscription_source_is_scoped_and_occurrence_is_server_reserved_once(): void
    {
        $company = $this->company('API CREATE SUBSCRIPTION OWNER');
        $contract = $this->contract($company);
        $otherContract = $this->contract($this->company('API CREATE SUBSCRIPTION OTHER'));
        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2026-09-01',
            'payment_terms' => 10,
        ]);
        $foreign = $this->subjectSubscription($otherContract);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload($contract, [$this->subscriptionLine($foreign)], 'API-CREATE-FOREIGN-SUB')
        )->assertUnprocessable()->assertJsonValidationErrors('lines.0.subscription_id');

        $forged = $this->payload($contract, [$this->subscriptionLine($subscription)], 'API-CREATE-FORGED-PERIOD');
        $forged['lines'][0]['period_start'] = '2030-01-01';
        $forged['lines'][0]['period_end'] = '2030-12-31';
        $forged['lines'][0]['billing_occurrence_key'] = str_repeat('f', 64);
        $this->postJson(route('api.companies.invoices.store', $company), $forged)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'lines.0.period_start',
                'lines.0.period_end',
                'lines.0.billing_occurrence_key',
            ]);

        $payload = $this->payload($contract, [$this->subscriptionLine($subscription)], 'API-CREATE-SUB');
        $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertCreated()
            ->assertJsonPath('due_date', '2026-08-11')
            ->assertJsonPath('lines.0.period_start', '2026-09-01')
            ->assertJsonPath('lines.0.period_end', '2026-09-30')
            ->assertJsonMissingPath('lines.0.billing_occurrence_key');

        $line = InvoiceLine::query()->sole();
        $this->assertSame('2026-09-01', $line->period_start->toDateString());
        $this->assertSame('2026-09-30', $line->period_end->toDateString());
        $this->assertSame(64, strlen($line->billing_occurrence_key));

        $duplicate = $this->payload($contract, [$this->subscriptionLine($subscription)], 'API-CREATE-SUB-DUP');
        $this->postJson(route('api.companies.invoices.store', $company), $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 1);
    }

    public function test_source_exclusivity_and_duplicate_order_rejection_are_atomic(): void
    {
        $company = $this->company('API CREATE SOURCE SHAPE');
        $contract = $this->contract($company);
        $order = $this->subjectOrder($contract);
        $subscription = $this->subjectSubscription($contract);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $both = $this->payload($contract, [[
            'order_id' => $order->id,
            'subscription_id' => $subscription->id,
            'description' => 'Ambiguous',
            'amount' => '10.00',
        ]], 'API-CREATE-BOTH');
        $this->postJson(route('api.companies.invoices.store', $company), $both)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0');

        $unknownSource = $this->payload($contract, [[
            'source_id' => $order->id,
            'description' => 'Unknown source field',
            'amount' => '10.00',
        ]], 'API-CREATE-UNKNOWN-SOURCE-FIELD');
        $this->postJson(route('api.companies.invoices.store', $company), $unknownSource)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0');

        $duplicate = $this->payload($contract, [
            $this->orderLine($order),
            $this->orderLine($order),
        ], 'API-CREATE-DUPLICATE-ORDER');
        $this->postJson(route('api.companies.invoices.store', $company), $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.1.order_id');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    public function test_distinct_sources_and_duplicate_manual_descriptions_are_allowed(): void
    {
        $company = $this->company('API CREATE DISTINCT SOURCES');
        $contract = $this->contract($company);
        $firstOrder = $this->subjectOrder($contract, ['payment_terms' => 30]);
        $secondOrder = $this->subjectOrder($contract, ['payment_terms' => 20]);
        $subscription = $this->subjectSubscription($contract, ['payment_terms' => 10]);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $lines = [
            $this->orderLine($firstOrder),
            $this->orderLine($secondOrder),
            $this->subscriptionLine($subscription),
            $this->manualLine('Repeated manual'),
            $this->manualLine('Repeated manual'),
        ];
        $this->postJson(
            route('api.companies.invoices.store', $company),
            $this->payload($contract, $lines, 'API-CREATE-DISTINCT')
        )->assertCreated()->assertJsonPath('due_date', '2026-08-11');

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_lines', 5);
    }

    public function test_amount_rules_reject_invalid_values_without_writes(): void
    {
        $company = $this->company('API CREATE AMOUNT INVALID');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        foreach (['0', '0.00', '0.001', '1.234', '-1', '1e2'] as $index => $amount) {
            $payload = $this->payload($contract, [[
                'description' => 'Invalid amount',
                'amount' => $amount,
            ]], "API-CREATE-AMOUNT-INVALID-{$index}");

            $this->postJson(route('api.companies.invoices.store', $company), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('lines.0.amount');
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    public function test_decimal_amounts_sum_exactly_and_client_total_is_ignored(): void
    {
        $company = $this->company('API CREATE AMOUNT EXACT');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $payload = $this->payload($contract, [
            $this->manualLine('0.01', '0.01'),
            $this->manualLine('1', '1'),
            $this->manualLine('1.2', '1.2'),
            $this->manualLine('1.23', '1.23'),
            $this->manualLine('0.10', '0.10'),
            $this->manualLine('0.20', '0.20'),
        ], 'API-CREATE-AMOUNT-EXACT');
        $payload['total_amount'] = '999999.99';

        $response = $this->postJson(route('api.companies.invoices.store', $company), $payload)
            ->assertCreated()
            ->assertJsonPath('total_amount', '3.74')
            ->assertJsonPath('remaining_amount', '3.74');

        $this->assertSame('3.74', Invoice::query()->sole()->total_amount);
        $this->assertSame(
            ['0.01', '1.00', '1.20', '1.23', '0.10', '0.20'],
            array_column($response->json('lines'), 'amount')
        );
    }

    public function test_manual_due_date_is_required_and_cannot_precede_issue_date(): void
    {
        $company = $this->company('API CREATE MANUAL DUE');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);

        $missing = $this->payload($contract, [$this->manualLine()], 'API-CREATE-DUE-MISSING');
        unset($missing['due_date']);
        $this->postJson(route('api.companies.invoices.store', $company), $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $early = $this->payload($contract, [$this->manualLine()], 'API-CREATE-DUE-EARLY');
        $early['due_date'] = '2026-07-31';
        $this->postJson(route('api.companies.invoices.store', $company), $early)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $valid = $this->payload($contract, [$this->manualLine()], 'API-CREATE-DUE-VALID');
        $valid['due_date'] = '2026-09-05';
        $this->postJson(route('api.companies.invoices.store', $company), $valid)
            ->assertCreated()
            ->assertJsonPath('due_date', '2026-09-05');
    }

    public function test_multiple_sources_use_batch_queries_instead_of_per_line_queries(): void
    {
        $company = $this->company('API CREATE BATCH SOURCES');
        $contract = $this->contract($company);
        $orders = collect(range(1, 3))->map(
            fn (int $index): Order => $this->subjectOrder($contract, [
                'title' => "Batch order {$index}",
                'payment_terms' => 30,
            ])
        );
        $subscriptions = collect(range(1, 3))->map(
            fn (int $index): Subscription => $this->subjectSubscription($contract, [
                'title' => "Batch subscription {$index}",
                'payment_terms' => 10,
            ])
        );
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $lines = [
            ...$orders->map(fn (Order $order): array => $this->orderLine($order))->all(),
            ...$subscriptions->map(fn (Subscription $subscription): array => $this->subscriptionLine($subscription))->all(),
        ];

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->postJson(
                route('api.companies.invoices.store', $company),
                $this->payload($contract, $lines, 'API-CREATE-BATCH')
            ),
        );

        $capture['result']->assertCreated();
        $orderQueries = array_filter(
            $capture['records'],
            fn (array $record): bool => in_array('orders', $record['tables'], true)
        );
        $subscriptionQueries = array_filter(
            $capture['records'],
            fn (array $record): bool => in_array('subscriptions', $record['tables'], true)
        );
        $this->assertSame(2, count($orderQueries));
        $this->assertSame(2, count($subscriptionQueries));
    }

    public function test_unexpected_line_creation_exception_rolls_back_invoice_and_all_lines(): void
    {
        $company = $this->company('API CREATE ROLLBACK');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::InvoicesCreate->value]);
        $creating = 0;
        Event::listen('eloquent.creating: '.InvoiceLine::class, function () use (&$creating): void {
            $creating++;
            if ($creating === 2) {
                throw new RuntimeException('Injected line creation failure');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson(
                route('api.companies.invoices.store', $company),
                $this->payload(
                    $contract,
                    [$this->manualLine('First'), $this->manualLine('Second')],
                    'API-CREATE-ROLLBACK'
                )
            );
            $this->fail('RuntimeException was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected line creation failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function payload(Contract $contract, array $lines, string $number): array
    {
        return [
            'contract_id' => $contract->id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '999.99',
            'comment' => 'Creation integrity comment',
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    private function manualLine(string $description = 'Manual line', string $amount = '10.00'): array
    {
        return compact('description', 'amount');
    }

    /** @return array<string, mixed> */
    private function orderLine(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'description' => $order->title,
            'amount' => $order->price,
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionLine(Subscription $subscription): array
    {
        return [
            'subscription_id' => $subscription->id,
            'description' => $subscription->title,
            'amount' => $subscription->amount,
        ];
    }

    /** @return array<string, string> */
    private function configureSeller(string $prefix): array
    {
        $token = strtoupper(substr(hash('sha256', $prefix), 0, 8));
        $snapshot = [
            'seller_name' => $prefix.' NAME',
            'seller_voen' => 'V'.$token,
            'seller_bank_name' => $prefix.' BANK',
            'seller_iban' => 'AZ00'.$token.'IBAN',
            'seller_bank_code' => 'C'.$token,
            'seller_bank_voen' => 'BV'.$token,
            'seller_swift' => 'S'.$token,
        ];

        config([
            'invoice.seller.name' => $snapshot['seller_name'],
            'invoice.seller.voen' => $snapshot['seller_voen'],
            'invoice.seller.bank_name' => $snapshot['seller_bank_name'],
            'invoice.seller.iban' => $snapshot['seller_iban'],
            'invoice.seller.bank_code' => $snapshot['seller_bank_code'],
            'invoice.seller.bank_voen' => $snapshot['seller_bank_voen'],
            'invoice.seller.swift' => $snapshot['seller_swift'],
        ]);

        Organization::query()->updateOrCreate(
            ['singleton_key' => Organization::SINGLETON_KEY],
            [
                'name' => $snapshot['seller_name'],
                'voen' => $snapshot['seller_voen'],
                'bank_name' => $snapshot['seller_bank_name'],
                'iban' => $snapshot['seller_iban'],
                'bank_code' => $snapshot['seller_bank_code'],
                'bank_voen' => $snapshot['seller_bank_voen'],
                'swift' => $snapshot['seller_swift'],
            ],
        );

        return $snapshot;
    }

    /** @param array<string, ?string> $snapshot */
    private function assertSellerSnapshot(Invoice $invoice, array $snapshot): void
    {
        foreach ($snapshot as $field => $value) {
            $this->assertSame($value, $invoice->getAttribute($field), $field);
        }
    }
}
