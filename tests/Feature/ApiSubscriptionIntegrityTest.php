<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\ServiceType;
use App\Models\Subscription;
use App\Services\InvoiceDueDateSynchronizer;
use App\Support\Access\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiSubscriptionIntegrityTest extends AuthorizationTestCase
{
    private const COMPACT_KEYS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'start_date',
        'billing_period',
        'custom_interval_value',
        'custom_interval_unit',
        'amount',
        'payment_terms',
        'status',
        'next_billing_date',
        'created_at',
        'updated_at',
        'service_type',
    ];

    private const DETAIL_KEYS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'start_date',
        'billing_period',
        'custom_interval_value',
        'custom_interval_unit',
        'amount',
        'payment_terms',
        'status',
        'next_billing_date',
        'comment',
        'created_at',
        'updated_at',
        'contract',
        'service_type',
    ];

    public function test_nested_index_is_parent_scoped_compact_stable_and_constant_query_count(): void
    {
        [$contract, $target, $markers] = $this->disclosureSubscription('SUBSCRIPTION-INDEX');
        $second = $this->subjectSubscription($contract, ['title' => 'SUBSCRIPTION-INDEX-SECOND']);
        $other = $this->subjectSubscription(
            $this->contract($this->company('SUBSCRIPTION-INDEX-OTHER-COMPANY')),
            ['title' => 'SUBSCRIPTION-INDEX-OTHER'],
        );
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.contracts.subscriptions.index', $contract)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame([$target->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[0]));
        $this->assertSame(['id', 'name', 'type'], array_keys($payload[0]['service_type']));
        $this->assertSame('subscription', $payload[0]['service_type']['type']);
        $this->assertSame('2026-08-01', $payload[0]['start_date']);
        $this->assertSame('2026-09-01', $payload[0]['next_billing_date']);
        $this->assertSame('432.10', $payload[0]['amount']);
        $this->assertSame(['contracts', 'subscriptions', 'service_types'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));

        foreach ([...$markers, $target->comment, $other->title] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_show_has_closed_detail_projection_and_no_company_or_financial_queries(): void
    {
        [$contract, $subscription, $markers] = $this->disclosureSubscription('SUBSCRIPTION-SHOW');
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.subscriptions.show', $subscription)),
        );

        $capture['result']->assertOk();
        $this->assertSame(self::DETAIL_KEYS, array_keys($capture['result']->json()));
        $capture['result']
            ->assertJsonPath('id', $subscription->id)
            ->assertJsonPath('contract', [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ])
            ->assertJsonPath('service_type', [
                'id' => $subscription->service_type_id,
                'name' => $subscription->serviceType->name,
                'type' => 'subscription',
            ])
            ->assertJsonPath('amount', '432.10')
            ->assertJsonPath('start_date', '2026-08-01')
            ->assertJsonPath('next_billing_date', '2026-09-01');
        $this->assertSame(['subscriptions', 'contracts', 'service_types'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));

        foreach ($markers as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_store_uses_bound_contract_lifecycle_and_subscription_service_type(): void
    {
        $contract = $this->contract($this->company('SUBSCRIPTION-STORE-A'));
        $otherContract = $this->contract($this->company('SUBSCRIPTION-STORE-B'));
        $serviceType = $this->subjectServiceType('subscription');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $response = $this->postJson(route('api.contracts.subscriptions.store', $contract), [
            ...$this->validStorePayload($serviceType),
            'billing_period' => 'custom',
            'custom_interval_value' => 45,
            'custom_interval_unit' => 'day',
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'id' => 9_000_201,
            'title' => 'SUBSCRIPTION-STORE-FORGED-TITLE',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertCreated();

        $subscription = Subscription::query()->where('contract_id', $contract->id)->sole();
        $this->assertSame($contract->id, $subscription->contract_id);
        $this->assertSame($serviceType->id, $subscription->service_type_id);
        $this->assertNotSame(9_000_201, $subscription->id);
        $this->assertNull($subscription->title);
        $this->assertSame('custom', $subscription->billing_period);
        $this->assertSame(45, $subscription->custom_interval_value);
        $this->assertSame('day', $subscription->custom_interval_unit);
        $this->assertSame('2026-08-10', $subscription->next_billing_date->toDateString());
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response
            ->assertJsonPath('contract', [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ])
            ->assertJsonPath('service_type.type', 'subscription')
            ->assertJsonPath('amount', '125.00')
            ->assertDontSee($otherContract->contract_number)
            ->assertDontSee('SUBSCRIPTION-STORE-FORGED-TITLE');
    }

    public function test_store_rejects_one_time_service_type_without_creating_subscription(): void
    {
        $contract = $this->contract($this->company('SUBSCRIPTION-STORE-TYPE'));
        $serviceType = $this->subjectServiceType('one_time');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->postJson(
            route('api.contracts.subscriptions.store', $contract),
            $this->validStorePayload($serviceType),
        )->assertUnprocessable()->assertJsonValidationErrors('service_type_id');

        $this->assertDatabaseMissing('subscriptions', [
            'contract_id' => $contract->id,
            'service_type_id' => $serviceType->id,
        ]);
    }

    public function test_client_next_billing_date_is_rejected_without_partial_store_or_update(): void
    {
        $contract = $this->contract($this->company('SUBSCRIPTION-NEXT-DATE'));
        $serviceType = $this->subjectServiceType('subscription');
        $this->actingAsPermissions([
            PermissionName::ContractSubjectsCreate->value,
            PermissionName::ContractSubjectsUpdate->value,
        ]);

        $this->postJson(route('api.contracts.subscriptions.store', $contract), [
            ...$this->validStorePayload($serviceType),
            'next_billing_date' => '2035-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_billing_date');
        $this->assertDatabaseCount('subscriptions', 0);

        $subscription = $this->subjectSubscription($contract, [
            'next_billing_date' => '2027-01-01',
        ]);
        $original = $subscription->getAttributes();
        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'next_billing_date' => '2035-01-01',
            'payment_terms' => 14,
            'comment' => 'SUBSCRIPTION-NEXT-DATE-FORBIDDEN',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_billing_date');

        $this->assertRawAttributesUnchanged($original, $subscription->fresh());
    }

    public function test_patch_updates_only_intended_subscription_without_reparenting_or_schedule_reset(): void
    {
        $contract = $this->contract($this->company('SUBSCRIPTION-UPDATE-A'));
        $otherContract = $this->contract($this->company('SUBSCRIPTION-UPDATE-B'));
        $target = $this->subjectSubscription($contract, [
            'next_billing_date' => '2027-02-01',
        ]);
        $other = $this->subjectSubscription($contract);
        $newType = $this->subjectServiceType('subscription');
        $originalStartDate = $target->start_date->toDateString();
        $originalPeriod = $target->billing_period;
        $otherAttributes = $other->getAttributes();
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $response = $this->patchJson(route('api.subscriptions.update', $target), [
            'service_type_id' => $newType->id,
            'amount' => '275.50',
            'payment_terms' => 7,
            'status' => 'suspended',
            'comment' => 'SUBSCRIPTION-UPDATE-CHANGED',
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'id' => 9_000_202,
            'created_at' => '2000-01-01 00:00:00',
        ])->assertOk();

        $target->refresh();
        $this->assertSame($contract->id, $target->contract_id);
        $this->assertSame($newType->id, $target->service_type_id);
        $this->assertSame($originalStartDate, $target->start_date->toDateString());
        $this->assertSame($originalPeriod, $target->billing_period);
        $this->assertSame('2027-02-01', $target->next_billing_date->toDateString());
        $this->assertSame('275.50', $target->amount);
        $this->assertSame(7, (int) $target->payment_terms);
        $this->assertSame('suspended', $target->status);
        $this->assertRawAttributesUnchanged($otherAttributes, $other->fresh());
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response->assertJsonPath('service_type.id', $newType->id);
    }

    public function test_incompatible_update_has_no_subscription_invoice_line_or_synchronizer_side_effects(): void
    {
        $contract = $this->contract($this->company('SUBSCRIPTION-UPDATE-TYPE'));
        $subscription = $this->subjectSubscription($contract);
        $oneTimeType = $this->subjectServiceType('one_time');
        $invoice = $this->invoiceForSubscription($subscription, 'SUBSCRIPTION-UPDATE-TYPE-INVOICE');
        $line = $invoice->lines()->where('subscription_id', $subscription->id)->sole();
        $originalSubscription = $subscription->getAttributes();
        $originalInvoice = $invoice->getAttributes();
        $originalLine = $line->getAttributes();
        $this->mock(InvoiceDueDateSynchronizer::class)
            ->shouldNotReceive('synchronizeForSubscription');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'service_type_id' => $oneTimeType->id,
            'amount' => '999.99',
            'payment_terms' => 7,
            'comment' => 'SUBSCRIPTION-UPDATE-TYPE-FORBIDDEN',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_type_id');

        $this->assertRawAttributesUnchanged($originalSubscription, $subscription->fresh());
        $this->assertRawAttributesUnchanged($originalInvoice, $invoice->fresh());
        $this->assertRawAttributesUnchanged($originalLine, $line->fresh());
    }

    public function test_update_preserves_linked_draft_invoice_due_date_synchronization(): void
    {
        $subscription = $this->subjectSubscription(
            $this->contract($this->company('SUBSCRIPTION-DUE-DATE')),
            ['payment_terms' => 30],
        );
        $invoice = $this->invoiceForSubscription($subscription, 'SUBSCRIPTION-DUE-DATE-INVOICE');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'payment_terms' => 7,
        ])->assertOk()->assertJsonPath('payment_terms', 7);

        $this->assertSame('2026-08-08', $invoice->fresh()->due_date);
    }

    public function test_history_blocks_schedule_change_without_blocking_ordinary_update(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company('SUBSCRIPTION-HISTORY')));
        $invoice = $this->invoiceForSubscription($subscription, 'SUBSCRIPTION-HISTORY-INVOICE', 'cancelled');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'start_date' => '2026-10-01',
            'payment_terms' => 14,
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertSame('2026-08-01', $subscription->fresh()->start_date->toDateString());

        $this->patchJson(route('api.subscriptions.update', $subscription), [
            'amount' => '333.33',
            'payment_terms' => 14,
            'comment' => 'SUBSCRIPTION-HISTORY-ORDINARY-UPDATE',
        ])->assertOk();
        $this->assertSame('333.33', $subscription->fresh()->amount);
        $this->assertSame('2026-08-31', $invoice->fresh()->due_date);
    }

    public function test_unused_subscription_is_deleted_and_repeated_delete_is_not_found(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company('SUBSCRIPTION-DELETE-EMPTY')));
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);

        $this->deleteJson(route('api.subscriptions.destroy', $subscription))
            ->assertOk()
            ->assertExactJson(['message' => 'Подписка удалена']);
        $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);

        $this->deleteJson(route('api.subscriptions.destroy', $subscription))->assertNotFound();
    }

    public function test_invoice_lines_block_delete_without_partial_financial_mutation(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company('SUBSCRIPTION-DELETE-BLOCKED')));
        $chain = $this->subjectFinancialChain($subscription);
        $secondLine = $chain['invoice']->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'SUBSCRIPTION-DELETE-SECOND-LINE',
            'amount' => '25.00',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);

        $this->deleteJson(route('api.subscriptions.destroy', $subscription))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Невозможно удалить — подписка включена в инвойс',
            ]);

        $this->assertSubjectFinancialChainExists($subscription, $chain);
        $this->assertDatabaseHas('invoice_lines', ['id' => $secondLine->id]);
    }

    public function test_unexpected_runtime_exception_is_not_converted_to_conflict(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company('SUBSCRIPTION-DELETE-RUNTIME')));
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        Subscription::deleting(function (Subscription $deleting) use ($subscription): void {
            if ($deleting->is($subscription)) {
                throw new RuntimeException('SUBSCRIPTION-DELETE-RUNTIME-MARKER');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.subscriptions.destroy', $subscription));
            $this->fail('The runtime exception was converted into an HTTP response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SUBSCRIPTION-DELETE-RUNTIME-MARKER', $exception->getMessage());
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    public function test_non_foreign_key_query_exception_is_not_converted_to_conflict(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company('SUBSCRIPTION-DELETE-QUERY')));
        $queryException = $this->queryException();
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        Subscription::deleting(function (Subscription $deleting) use ($subscription, $queryException): void {
            if ($deleting->is($subscription)) {
                throw $queryException;
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.subscriptions.destroy', $subscription));
            $this->fail('The non-FK query exception was converted into an HTTP response.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    /** @return array{Contract, Subscription, list<string>} */
    private function disclosureSubscription(string $prefix): array
    {
        $company = $this->company($prefix.'-COMPANY');
        $company->forceFill([
            'bank_name' => $prefix.'-BANK-SECRET',
            'iban' => 'AZ00'.$prefix.'-IBAN-SECRET',
            'comment' => $prefix.'-COMPANY-COMMENT-SECRET',
        ])->save();
        $company->creditBalance()->create(['amount' => '65432.10']);
        $contract = $this->contract($company);
        $contract->update(['comment' => $prefix.'-CONTRACT-COMMENT-SECRET']);
        $serviceType = $this->subjectServiceType('subscription');
        $serviceType->update(['base_price' => '98765.43']);
        $item = $serviceType->items()->create([
            'name' => $prefix.'-SERVICE-ITEM-SECRET',
            'price' => '87654.32',
        ]);
        $subscription = $this->subjectSubscription($contract, [
            'service_type_id' => $serviceType->id,
            'title' => $prefix.'-TARGET-SUBSCRIPTION',
            'start_date' => '2026-08-01',
            'next_billing_date' => '2026-09-01',
            'amount' => '432.10',
            'comment' => $prefix.'-TARGET-COMMENT',
        ]);
        $order = $this->subjectOrder($contract, ['title' => $prefix.'-ORDER-SECRET']);
        $invoice = $this->invoiceForSubscription($subscription, $prefix.'-INVOICE-SECRET');
        $line = $invoice->lines()->where('subscription_id', $subscription->id)->sole();
        $line->update([
            'description' => $prefix.'-INVOICE-LINE-SECRET',
            'amount' => '76543.21',
        ]);
        $payment = $this->payment($invoice, 'pending', $prefix.'-PAYMENT-SECRET');

        return [$contract, $subscription, [
            $company->bank_name,
            $company->iban,
            $company->comment,
            '65432.10',
            $contract->comment,
            $serviceType->base_price,
            $item->name,
            $item->price,
            $order->title,
            $line->description,
            $line->amount,
            $invoice->invoice_number,
            $payment->comment,
        ]];
    }

    /** @return array<string, mixed> */
    private function validStorePayload(ServiceType $serviceType): array
    {
        return [
            'service_type_id' => $serviceType->id,
            'start_date' => '2026-08-10',
            'billing_period' => 'monthly',
            'amount' => '125.00',
            'payment_terms' => 14,
            'status' => 'active',
            'comment' => 'SUBSCRIPTION-STORE-COMMENT',
        ];
    }

    private function invoiceForSubscription(
        Subscription $subscription,
        string $number,
        string $status = 'draft'
    ): Invoice {
        $invoice = $subscription->contract->invoices()->create([
            'company_id' => $subscription->contract->company_id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => $status,
        ]);
        $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => $number.'-LINE',
            'amount' => '100.00',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);

        return $invoice;
    }

    private function queryException(): QueryException
    {
        $previous = new PDOException('Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return new QueryException(
            'testing',
            'delete from subscriptions where id = ?',
            [1],
            $previous,
        );
    }

    /** @param array<string, mixed> $expected */
    private function assertRawAttributesUnchanged(array $expected, EloquentModel $actual): void
    {
        foreach ($expected as $attribute => $value) {
            $actualValue = $actual->getRawOriginal($attribute);
            if ($attribute === 'vat_enabled') {
                $this->assertSame((bool) $value, (bool) $actualValue);

                continue;
            }

            if (in_array($attribute, [
                'start_date',
                'next_billing_date',
                'issue_date',
                'due_date',
                'period_start',
                'period_end',
            ], true) && $value !== null) {
                $this->assertSame(
                    CarbonImmutable::parse($value)->toDateString(),
                    CarbonImmutable::parse($actual->getRawOriginal($attribute))->toDateString(),
                );

                continue;
            }

            $this->assertSame($value, $actualValue);
        }
    }
}
