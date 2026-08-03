<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ServiceType;
use App\Services\InvoiceDueDateSynchronizer;
use App\Support\Access\PermissionName;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiOrderIntegrityTest extends AuthorizationTestCase
{
    private const COMPACT_KEYS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'order_date',
        'deadline',
        'price',
        'payment_terms',
        'status',
        'created_at',
        'updated_at',
        'service_type',
    ];

    private const DETAIL_KEYS = [
        'id',
        'contract_id',
        'service_type_id',
        'title',
        'order_date',
        'deadline',
        'price',
        'payment_terms',
        'status',
        'comment',
        'created_at',
        'updated_at',
        'contract',
        'service_type',
    ];

    public function test_nested_index_is_parent_scoped_compact_stable_and_constant_query_count(): void
    {
        [$contract, $target, $markers] = $this->disclosureOrder('ORDER-INDEX');
        $second = $this->subjectOrder($contract, ['title' => 'ORDER-INDEX-SECOND']);
        $other = $this->subjectOrder(
            $this->contract($this->company('ORDER-INDEX-OTHER-COMPANY')),
            ['title' => 'ORDER-INDEX-OTHER'],
        );
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.contracts.orders.index', $contract)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame([$target->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[0]));
        $this->assertSame(['id', 'name', 'type'], array_keys($payload[0]['service_type']));
        $this->assertSame('one_time', $payload[0]['service_type']['type']);
        $this->assertSame('2026-08-01', $payload[0]['order_date']);
        $this->assertNull($payload[0]['deadline']);
        $this->assertSame('432.10', $payload[0]['price']);
        $this->assertSame(['contracts', 'orders', 'service_types'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));

        foreach ([...$markers, $target->comment, $other->title] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_show_has_closed_detail_projection_and_no_company_or_financial_queries(): void
    {
        [$contract, $order, $markers] = $this->disclosureOrder('ORDER-SHOW');
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.orders.show', $order)),
        );

        $capture['result']->assertOk();
        $this->assertSame(self::DETAIL_KEYS, array_keys($capture['result']->json()));
        $capture['result']
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('contract', [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ])
            ->assertJsonPath('service_type', [
                'id' => $order->service_type_id,
                'name' => $order->serviceType->name,
                'type' => 'one_time',
            ])
            ->assertJsonPath('price', '432.10')
            ->assertJsonPath('deadline', null);
        $this->assertSame(['orders', 'contracts', 'service_types'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(3, DomainQueryRecorder::count($capture['records']));

        foreach ($markers as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_store_uses_bound_contract_ignores_server_fields_and_accepts_one_time_type(): void
    {
        $contract = $this->contract($this->company('ORDER-STORE-A'));
        $otherContract = $this->contract($this->company('ORDER-STORE-B'));
        $serviceType = $this->subjectServiceType('one_time');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $response = $this->postJson(route('api.contracts.orders.store', $contract), [
            ...$this->validStorePayload($serviceType),
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'id' => 9_000_101,
            'title' => 'ORDER-STORE-FORGED-TITLE',
            'deadline' => '2035-01-01',
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertCreated();

        $order = Order::query()->where('contract_id', $contract->id)->sole();
        $this->assertSame($contract->id, $order->contract_id);
        $this->assertSame($serviceType->id, $order->service_type_id);
        $this->assertNotSame(9_000_101, $order->id);
        $this->assertNull($order->title);
        $this->assertNull($order->deadline);
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response
            ->assertJsonPath('contract', [
                'id' => $contract->id,
                'company_id' => $contract->company_id,
                'contract_number' => $contract->contract_number,
            ])
            ->assertJsonPath('service_type', [
                'id' => $serviceType->id,
                'name' => $serviceType->name,
                'type' => 'one_time',
            ])
            ->assertJsonPath('price', '125.00')
            ->assertDontSee($otherContract->contract_number)
            ->assertDontSee('ORDER-STORE-FORGED-TITLE');
    }

    public function test_store_rejects_subscription_service_type_without_creating_order(): void
    {
        $contract = $this->contract($this->company('ORDER-STORE-TYPE'));
        $serviceType = $this->subjectServiceType('subscription');
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->postJson(
            route('api.contracts.orders.store', $contract),
            $this->validStorePayload($serviceType),
        )->assertUnprocessable()->assertJsonValidationErrors('service_type_id');

        $this->assertDatabaseMissing('orders', [
            'contract_id' => $contract->id,
            'service_type_id' => $serviceType->id,
        ]);
    }

    public function test_patch_updates_only_intended_order_and_cannot_change_contract_or_server_fields(): void
    {
        $contract = $this->contract($this->company('ORDER-UPDATE-A'));
        $otherContract = $this->contract($this->company('ORDER-UPDATE-B'));
        $target = $this->subjectOrder($contract, ['deadline' => '2026-10-01']);
        $other = $this->subjectOrder($contract);
        $newType = $this->subjectServiceType('one_time');
        $originalOrderDate = $target->order_date;
        $originalPrice = $target->price;
        $otherAttributes = $other->getAttributes();
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $response = $this->patchJson(route('api.orders.update', $target), [
            'service_type_id' => $newType->id,
            'payment_terms' => 7,
            'status' => 'completed',
            'comment' => 'ORDER-UPDATE-CHANGED',
            'contract_id' => $otherContract->id,
            'company_id' => $otherContract->company_id,
            'id' => 9_000_102,
            'title' => 'ORDER-UPDATE-FORGED-TITLE',
            'deadline' => '2035-01-01',
            'created_at' => '2000-01-01 00:00:00',
        ])->assertOk();

        $target->refresh();
        $this->assertSame($contract->id, $target->contract_id);
        $this->assertSame($newType->id, $target->service_type_id);
        $this->assertSame($originalOrderDate, $target->order_date);
        $this->assertSame($originalPrice, $target->price);
        $this->assertSame('2026-10-01', $target->deadline);
        $this->assertSame('Authorization order', $target->title);
        $this->assertSame(7, (int) $target->payment_terms);
        $this->assertSame('completed', $target->status);
        $this->assertOrderAttributesUnchanged($otherAttributes, $other->fresh());
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response->assertJsonPath('service_type.id', $newType->id);
    }

    public function test_incompatible_update_has_no_order_invoice_or_synchronizer_side_effects(): void
    {
        $contract = $this->contract($this->company('ORDER-UPDATE-TYPE'));
        $order = $this->subjectOrder($contract);
        $subscriptionType = $this->subjectServiceType('subscription');
        $invoice = $this->invoiceForOrder($order, 'ORDER-UPDATE-TYPE-INVOICE');
        $originalOrder = $order->getAttributes();
        $originalInvoice = $invoice->getAttributes();
        $this->mock(InvoiceDueDateSynchronizer::class)
            ->shouldNotReceive('synchronizeForOrder');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.orders.update', $order), [
            'service_type_id' => $subscriptionType->id,
            'payment_terms' => 7,
            'comment' => 'ORDER-UPDATE-TYPE-FORBIDDEN',
        ])->assertUnprocessable()->assertJsonValidationErrors('service_type_id');

        $this->assertOrderAttributesUnchanged($originalOrder, $order->fresh());
        $freshInvoice = $invoice->fresh();
        foreach ($originalInvoice as $attribute => $value) {
            $this->assertSame($value, $freshInvoice->getRawOriginal($attribute));
        }
    }

    public function test_update_preserves_linked_draft_invoice_due_date_synchronization(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DUE-DATE')), [
            'payment_terms' => 30,
        ]);
        $invoice = $this->invoiceForOrder($order, 'ORDER-DUE-DATE-INVOICE');
        $this->actingAsPermissions([PermissionName::ContractSubjectsUpdate->value]);

        $this->patchJson(route('api.orders.update', $order), [
            'payment_terms' => 7,
        ])->assertOk()->assertJsonPath('payment_terms', 7);

        $this->assertSame('2026-08-08', $invoice->fresh()->due_date);
    }

    public function test_unused_order_is_deleted_and_repeated_delete_is_not_found(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DELETE-EMPTY')));
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);

        $this->deleteJson(route('api.orders.destroy', $order))
            ->assertOk()
            ->assertExactJson(['message' => 'Заказ удалён']);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);

        $this->deleteJson(route('api.orders.destroy', $order))->assertNotFound();
    }

    public function test_invoice_lines_block_delete_without_partial_financial_mutation(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DELETE-BLOCKED')));
        $chain = $this->subjectFinancialChain($order);
        $secondLine = $chain['invoice']->lines()->create([
            'order_id' => $order->id,
            'description' => 'ORDER-DELETE-SECOND-LINE',
            'amount' => '25.00',
        ]);
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);

        $this->deleteJson(route('api.orders.destroy', $order))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Невозможно удалить — заказ уже включён в инвойс',
            ]);

        $this->assertSubjectFinancialChainExists($order, $chain);
        $this->assertDatabaseHas('invoice_lines', ['id' => $secondLine->id]);
    }

    public function test_unexpected_runtime_exception_is_not_converted_to_conflict(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DELETE-RUNTIME')));
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        Order::deleting(function (Order $deleting) use ($order): void {
            if ($deleting->is($order)) {
                throw new RuntimeException('ORDER-DELETE-RUNTIME-MARKER');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.orders.destroy', $order));
            $this->fail('The runtime exception was converted into an HTTP response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ORDER-DELETE-RUNTIME-MARKER', $exception->getMessage());
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_non_foreign_key_query_exception_is_not_converted_to_conflict(): void
    {
        $order = $this->subjectOrder($this->contract($this->company('ORDER-DELETE-QUERY')));
        $queryException = $this->queryException();
        $this->actingAsPermissions([PermissionName::ContractSubjectsDelete->value]);
        Order::deleting(function (Order $deleting) use ($order, $queryException): void {
            if ($deleting->is($order)) {
                throw $queryException;
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.orders.destroy', $order));
            $this->fail('The non-FK query exception was converted into an HTTP response.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /** @return array{Contract, Order, list<string>} */
    private function disclosureOrder(string $prefix): array
    {
        $company = $this->company($prefix.'-COMPANY');
        $company->forceFill([
            'bank_name' => $prefix.'-BANK-SECRET',
            'iban' => 'AZ00'.$prefix.'-IBAN-SECRET',
            'comment' => $prefix.'-COMPANY-COMMENT-SECRET',
        ])->save();
        $contract = $this->contract($company);
        $contract->update(['comment' => $prefix.'-CONTRACT-COMMENT-SECRET']);
        $serviceType = $this->subjectServiceType('one_time');
        $serviceType->update(['base_price' => '98765.43']);
        $item = $serviceType->items()->create([
            'name' => $prefix.'-SERVICE-ITEM-SECRET',
            'price' => '87654.32',
        ]);
        $order = $this->subjectOrder($contract, [
            'service_type_id' => $serviceType->id,
            'title' => $prefix.'-TARGET-ORDER',
            'order_date' => '2026-08-01',
            'deadline' => null,
            'price' => '432.10',
            'comment' => $prefix.'-TARGET-COMMENT',
        ]);
        $subscription = $this->subjectSubscription($contract, [
            'title' => $prefix.'-SUBSCRIPTION-SECRET',
        ]);
        $invoice = $this->invoiceForOrder($order, $prefix.'-INVOICE-SECRET');
        $line = $invoice->lines()->where('order_id', $order->id)->sole();
        $line->update([
            'description' => $prefix.'-INVOICE-LINE-SECRET',
            'amount' => '76543.21',
        ]);
        $payment = $this->payment($invoice, 'pending', $prefix.'-PAYMENT-SECRET');

        return [$contract, $order, [
            $company->bank_name,
            $company->iban,
            $company->comment,
            $contract->comment,
            $serviceType->base_price,
            $item->name,
            $item->price,
            $subscription->title,
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
            'order_date' => '2026-08-10',
            'price' => '125.00',
            'payment_terms' => 14,
            'status' => 'in_progress',
            'comment' => 'ORDER-STORE-COMMENT',
        ];
    }

    private function invoiceForOrder(Order $order, string $number): Invoice
    {
        $invoice = $order->contract->invoices()->create([
            'company_id' => $order->contract->company_id,
            'invoice_number' => $number,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $invoice->lines()->create([
            'order_id' => $order->id,
            'description' => $number.'-LINE',
            'amount' => '100.00',
        ]);

        return $invoice;
    }

    private function queryException(): QueryException
    {
        $previous = new PDOException('Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return new QueryException(
            'testing',
            'delete from orders where id = ?',
            [1],
            $previous,
        );
    }

    /** @param array<string, mixed> $expected */
    private function assertOrderAttributesUnchanged(array $expected, Order $actual): void
    {
        foreach ($expected as $attribute => $value) {
            $this->assertSame($value, $actual->getRawOriginal($attribute));
        }
    }
}
