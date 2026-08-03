<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Access\PermissionName;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiContractIntegrityTest extends AuthorizationTestCase
{
    private const COMPACT_KEYS = [
        'id',
        'company_id',
        'contract_number',
        'start_date',
        'end_date',
        'status',
        'created_at',
        'updated_at',
    ];

    private const DETAIL_KEYS = [
        'id',
        'company_id',
        'contract_number',
        'start_date',
        'end_date',
        'status',
        'comment',
        'created_at',
        'updated_at',
        'company',
    ];

    public function test_nested_index_is_parent_scoped_compact_stable_and_constant_query_count(): void
    {
        $company = $this->company('CONTRACT-INDEX-A');
        $otherCompany = $this->company('CONTRACT-INDEX-B');
        $first = $this->contract($company);
        $first->update(['comment' => 'INDEX-CONTRACT-COMMENT-SECRET']);
        $second = $this->contract($company);
        $other = $this->contract($otherCompany);
        $order = $this->subjectOrder($first, ['title' => 'INDEX-ORDER-SECRET']);
        $subscription = $this->subjectSubscription($first, ['title' => 'INDEX-SUBSCRIPTION-SECRET']);
        $document = $this->document($first, 'INDEX-DOCUMENT-SECRET');
        [$invoice, $line] = $this->invoiceWithLine($first, 'INDEX-INVOICE-SECRET');
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.companies.contracts.index', $company)),
        );

        $capture['result']->assertOk();
        $payload = $capture['result']->json();
        $this->assertSame([$first->id, $second->id], array_column($payload, 'id'));
        $this->assertSame(self::COMPACT_KEYS, array_keys($payload[0]));
        $this->assertSame($first->start_date->toJSON(), $payload[0]['start_date']);
        $this->assertSame(['companies', 'contracts'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));

        foreach ([
            $first->comment,
            $other->contract_number,
            $order->title,
            $subscription->title,
            $document->original_name,
            $invoice->invoice_number,
            $line->description,
        ] as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
    }

    public function test_show_has_closed_detail_projection_and_no_child_or_financial_queries(): void
    {
        [$contract, $markers] = $this->disclosureContract('CONTRACT-SHOW');
        $this->actingAsPermissions([PermissionName::ContractsView->value]);

        $capture = (new DomainQueryRecorder)->capture(
            fn () => $this->getJson(route('api.contracts.show', $contract)),
        );

        $capture['result']->assertOk();
        $this->assertSame(self::DETAIL_KEYS, array_keys($capture['result']->json()));
        $capture['result']
            ->assertJsonPath('id', $contract->id)
            ->assertJsonPath('comment', 'CONTRACT-SHOW-CONTRACT-COMMENT')
            ->assertJsonPath('company', [
                'id' => $contract->company_id,
                'name' => 'CONTRACT-SHOW-COMPANY',
                'short_name' => 'CONTRACT-SHOW-SHORT',
            ]);
        $this->assertSame($contract->start_date->toJSON(), $capture['result']->json('start_date'));
        $this->assertSame(['contracts', 'companies'], DomainQueryRecorder::tables($capture['records']));
        $this->assertSame(2, DomainQueryRecorder::count($capture['records']));

        foreach ($markers as $marker) {
            $capture['result']->assertDontSee((string) $marker);
        }
        $this->assertNotContains(321, Arr::flatten($capture['result']->json()), true);
    }

    public function test_store_uses_bound_company_ignores_server_fields_and_returns_detail_projection(): void
    {
        $company = Company::query()->create([
            ...$this->companyPayload('CONTRACT-STORE-A'),
            'short_name' => 'STORE-A-SHORT',
        ]);
        $otherCompany = $this->company('CONTRACT-STORE-B');
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $response = $this->postJson(route('api.companies.contracts.store', $company), [
            'company_id' => $otherCompany->id,
            'contract_number' => 'CONTRACT-STORE-NUMBER',
            'start_date' => '2026-08-01',
            'end_date' => '2027-08-01',
            'status' => 'terminated',
            'comment' => 'CONTRACT-STORE-COMMENT',
            'id' => 9_000_003,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ])->assertCreated();

        $contract = Contract::query()->where('contract_number', 'CONTRACT-STORE-NUMBER')->sole();
        $this->assertSame($company->id, $contract->company_id);
        $this->assertNotSame(9_000_003, $contract->id);
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response
            ->assertJsonPath('company', [
                'id' => $company->id,
                'name' => $company->name,
                'short_name' => 'STORE-A-SHORT',
            ])
            ->assertDontSee($company->iban)
            ->assertDontSee($otherCompany->name);
    }

    public function test_store_rejects_signed_document_without_creating_contract(): void
    {
        $company = $this->company('CONTRACT-STORE-DOCUMENT');
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->postJson(route('api.companies.contracts.store', $company), [
            'contract_number' => 'CONTRACT-SIGNED-DOCUMENT',
            'start_date' => '2026-08-01',
            'signed_document' => 'legacy/path.pdf',
        ])->assertUnprocessable()->assertJsonValidationErrors('signed_document');

        $this->assertDatabaseMissing('contracts', ['contract_number' => 'CONTRACT-SIGNED-DOCUMENT']);
    }

    public function test_patch_changes_only_intended_contract_and_cannot_move_company(): void
    {
        $company = $this->company('CONTRACT-UPDATE-A');
        $otherCompany = $this->company('CONTRACT-UPDATE-B');
        $target = $this->contract($company);
        $other = $this->contract($company);
        $originalNumber = $target->contract_number;
        $otherComment = $other->comment;
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $response = $this->patchJson(route('api.contracts.update', $target), [
            'company_id' => $otherCompany->id,
            'comment' => 'CONTRACT-UPDATE-CHANGED',
            'status' => 'terminated',
            'id' => 9_000_004,
            'created_at' => '2000-01-01 00:00:00',
        ])->assertOk();

        $target->refresh();
        $other->refresh();
        $this->assertSame($company->id, $target->company_id);
        $this->assertSame($originalNumber, $target->contract_number);
        $this->assertSame('CONTRACT-UPDATE-CHANGED', $target->comment);
        $this->assertSame('terminated', $target->status);
        $this->assertSame($otherComment, $other->comment);
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json()));
        $response->assertJsonPath('company.id', $company->id);
    }

    public function test_update_rejects_signed_document_without_partial_mutation(): void
    {
        $contract = $this->contract($this->company('CONTRACT-UPDATE-DOCUMENT'));
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $this->patchJson(route('api.contracts.update', $contract), [
            'comment' => 'SHOULD-NOT-BE-SAVED',
            'signed_document' => 'legacy/path.pdf',
        ])->assertUnprocessable()->assertJsonValidationErrors('signed_document');

        $this->assertNotSame('SHOULD-NOT-BE-SAVED', $contract->fresh()->comment);
    }

    public function test_empty_contract_is_deleted_and_repeated_delete_is_not_found(): void
    {
        $contract = $this->contract($this->company('CONTRACT-DELETE-EMPTY'));
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->deleteJson(route('api.contracts.destroy', $contract))
            ->assertOk()
            ->assertExactJson(['message' => 'Контракт удалён']);
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);

        $this->deleteJson(route('api.contracts.destroy', $contract))->assertNotFound();
    }

    #[DataProvider('blockingDependencyProvider')]
    public function test_dependencies_return_conflict_without_partial_delete(string $dependency): void
    {
        $contract = $this->contract($this->company('CONTRACT-DELETE-'.$dependency));
        $children = $this->createDependencies($contract, $dependency);
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->deleteJson(route('api.contracts.destroy', $contract))
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.',
            ]);

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        foreach ($children as [$table, $id]) {
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
    }

    public function test_unexpected_runtime_exception_is_not_converted_to_conflict(): void
    {
        $contract = $this->contract($this->company('CONTRACT-DELETE-RUNTIME'));
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);
        Contract::deleting(function (Contract $deleting) use ($contract): void {
            if ($deleting->is($contract)) {
                throw new RuntimeException('CONTRACT-DELETE-RUNTIME-MARKER');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.contracts.destroy', $contract));
            $this->fail('The runtime exception was converted into an HTTP response.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CONTRACT-DELETE-RUNTIME-MARKER', $exception->getMessage());
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
    }

    public function test_non_foreign_key_query_exception_is_not_converted_to_conflict(): void
    {
        $contract = $this->contract($this->company('CONTRACT-DELETE-QUERY'));
        $queryException = $this->queryException();
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);
        Contract::deleting(function (Contract $deleting) use ($contract, $queryException): void {
            if ($deleting->is($contract)) {
                throw $queryException;
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->deleteJson(route('api.contracts.destroy', $contract));
            $this->fail('The non-FK query exception was converted into an HTTP response.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
    }

    public static function blockingDependencyProvider(): array
    {
        return [
            'order' => ['order'],
            'subscription' => ['subscription'],
            'document' => ['document'],
            'invoice' => ['invoice'],
            'multiple' => ['multiple'],
        ];
    }

    /** @return array{Contract, list<string>} */
    private function disclosureContract(string $prefix): array
    {
        $company = Company::query()->create([
            ...$this->companyPayload($prefix.'-COMPANY'),
            'short_name' => $prefix.'-SHORT',
            'bank_name' => $prefix.'-BANK-NAME',
            'comment' => $prefix.'-COMPANY-COMMENT',
        ]);
        $contract = $this->contract($company);
        $contract->update(['comment' => $prefix.'-CONTRACT-COMMENT']);
        $order = $this->subjectOrder($contract, [
            'title' => $prefix.'-ORDER-TITLE',
            'comment' => $prefix.'-ORDER-COMMENT',
            'price' => '87654.32',
        ]);
        $subscription = $this->subjectSubscription($contract, [
            'title' => $prefix.'-SUBSCRIPTION-TITLE',
            'comment' => $prefix.'-SUBSCRIPTION-COMMENT',
            'amount' => '76543.21',
            'payment_terms' => 321,
        ]);
        $document = $this->document($contract, $prefix.'-DOCUMENT');
        [$invoice, $line] = $this->invoiceWithLine($contract, $prefix.'-INVOICE');

        return [$contract, [
            $company->voen,
            $company->bank_name,
            $company->iban,
            $company->swift,
            $company->legal_address,
            $company->actual_address,
            $company->phone,
            $company->email,
            $company->website,
            $company->comment,
            $order->title,
            $order->comment,
            '87654.32',
            $subscription->title,
            $subscription->comment,
            '76543.21',
            $document->original_name,
            $document->comment,
            $document->file_path,
            $invoice->invoice_number,
            $line->description,
        ]];
    }

    private function document(Contract $contract, string $prefix): ContractDocument
    {
        return $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => $prefix.'-ORIGINAL.pdf',
            'file_path' => 'contract-documents/'.$contract->id.'/'.$prefix.'-PATH.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 456789,
            'comment' => $prefix.'-COMMENT',
        ]);
    }

    /** @return array{Invoice, InvoiceLine} */
    private function invoiceWithLine(Contract $contract, string $prefix): array
    {
        $invoice = $contract->invoices()->create([
            'company_id' => $contract->company_id,
            'invoice_number' => $prefix.'-NUMBER',
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => '65432.10',
            'status' => 'draft',
        ]);
        $line = $invoice->lines()->create([
            'description' => $prefix.'-LINE-DESCRIPTION',
            'amount' => '54321.09',
        ]);

        return [$invoice, $line];
    }

    /** @return list<array{string, int}> */
    private function createDependencies(Contract $contract, string $dependency): array
    {
        $children = [];

        if (in_array($dependency, ['order', 'multiple'], true)) {
            $order = $this->subjectOrder($contract);
            $children[] = ['orders', $order->id];
        }
        if (in_array($dependency, ['subscription', 'multiple'], true)) {
            $subscription = $this->subjectSubscription($contract);
            $children[] = ['subscriptions', $subscription->id];
        }
        if (in_array($dependency, ['document', 'multiple'], true)) {
            $document = $this->document($contract, 'DELETE-DOCUMENT');
            $children[] = ['contract_documents', $document->id];
        }
        if (in_array($dependency, ['invoice', 'multiple'], true)) {
            [$invoice] = $this->invoiceWithLine($contract, 'DELETE-INVOICE');
            $children[] = ['invoices', $invoice->id];
        }

        return $children;
    }

    private function queryException(): QueryException
    {
        $previous = new PDOException('Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

        return new QueryException(
            'testing',
            'delete from contracts where id = ?',
            [1],
            $previous,
        );
    }
}
