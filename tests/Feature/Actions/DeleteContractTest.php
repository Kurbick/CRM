<?php

namespace Tests\Feature\Actions;

use App\Actions\Contracts\ContractDeletionDependencies;
use App\Actions\Contracts\DeleteContract;
use App\Exceptions\ContractDeletionException;
use App\Models\Contract;
use App\Models\ContractDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class DeleteContractTest extends AuthorizationTestCase
{
    public function test_empty_contract_is_deleted(): void
    {
        $contract = $this->contract($this->company());

        app(DeleteContract::class)->handle($contract);

        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
    }

    #[DataProvider('dependencyProvider')]
    public function test_each_dependency_blocks_deletion(string $dependency): void
    {
        $contract = $this->contract($this->company('Dependency '.$dependency));
        $this->createDependency($contract, $dependency);

        try {
            app(DeleteContract::class)->handle($contract);
            $this->fail('A dependent contract was deleted.');
        } catch (ContractDeletionException $exception) {
            $this->assertSame(
                'Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertSame(1, DB::table($dependency)->where('contract_id', $contract->id)->count());
    }

    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    #[DataProvider('foreignKeyErrorInfoProvider')]
    public function test_foreign_key_exception_is_wrapped_and_transaction_changes_are_rolled_back(
        array $errorInfo
    ): void {
        $contract = $this->contract($this->company('Rollback contract'));
        $document = $this->document($contract);
        $queryException = $this->queryException($errorInfo);
        $dependencies = Mockery::mock(ContractDeletionDependencies::class);
        $dependencies->shouldReceive('hasBlockingDependencies')->once()->andReturnFalse();
        $deletedEventReached = false;
        $contractWasDeletedBeforeFailure = false;
        $documentWasDeletedBeforeFailure = false;

        Contract::deleted(function (Contract $deletedContract) use (
            $contract,
            $document,
            $queryException,
            &$deletedEventReached,
            &$contractWasDeletedBeforeFailure,
            &$documentWasDeletedBeforeFailure
        ): void {
            if ($deletedContract->is($contract)) {
                $deletedEventReached = true;
                $contractWasDeletedBeforeFailure = ! DB::table('contracts')
                    ->where('id', $contract->id)
                    ->exists();
                $documentWasDeletedBeforeFailure = ! DB::table('contract_documents')
                    ->where('id', $document->id)
                    ->exists();
                throw $queryException;
            }
        });

        try {
            (new DeleteContract($dependencies))->handle($contract);
            $this->fail('The foreign key violation was not converted.');
        } catch (ContractDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
            $this->assertStringNotContainsString('delete from', $exception->getMessage());
        }

        $this->assertTrue($deletedEventReached);
        $this->assertTrue($contractWasDeletedBeforeFailure);
        $this->assertTrue($documentWasDeletedBeforeFailure);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
    }

    public function test_non_foreign_key_exception_is_rethrown_unchanged(): void
    {
        $contract = $this->contract($this->company('Non FK contract'));
        $queryException = $this->queryException([
            '23000',
            1062,
            'Duplicate entry for unique key',
        ]);
        $dependencies = Mockery::mock(ContractDeletionDependencies::class);
        $dependencies->shouldReceive('hasBlockingDependencies')->once()->andReturnFalse();

        Contract::deleting(function (Contract $deletingContract) use ($contract, $queryException): void {
            if ($deletingContract->is($contract)) {
                throw $queryException;
            }
        });

        try {
            (new DeleteContract($dependencies))->handle($contract);
            $this->fail('The non-FK exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
    }

    public static function dependencyProvider(): array
    {
        return [
            'order' => ['orders'],
            'subscription' => ['subscriptions'],
            'document' => ['contract_documents'],
            'invoice' => ['invoices'],
        ];
    }

    public static function foreignKeyErrorInfoProvider(): array
    {
        return [
            'PostgreSQL' => [['23503', 7, 'foreign key violation']],
            'MySQL or MariaDB' => [['23000', 1451, 'Cannot delete a parent row']],
            'SQLite' => [['23000', 19, 'FOREIGN KEY constraint failed']],
        ];
    }

    private function createDependency(Contract $contract, string $table): void
    {
        match ($table) {
            'orders' => $contract->orders()->create([
                'service_type_id' => $this->serviceType('one_time'),
                'order_date' => '2026-08-01',
                'price' => '10.00',
                'payment_terms' => 14,
            ]),
            'subscriptions' => $contract->subscriptions()->create([
                'service_type_id' => $this->serviceType('subscription'),
                'start_date' => '2026-08-01',
                'next_billing_date' => '2026-09-01',
                'billing_period' => 'monthly',
                'amount' => '10.00',
                'payment_terms' => 14,
            ]),
            'contract_documents' => $this->document($contract),
            'invoices' => $contract->invoices()->create([
                'company_id' => $contract->company_id,
                'invoice_number' => 'DELETE-CONTRACT-'.uniqid(),
                'issue_date' => '2026-08-01',
                'due_date' => '2026-08-15',
                'total_amount' => '0.00',
                'status' => 'draft',
            ]),
        };
    }

    private function serviceType(string $type): int
    {
        return DB::table('service_types')->insertGetId([
            'name' => 'Delete contract '.$type.' '.uniqid(),
            'base_price' => '10.00',
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function document(Contract $contract): ContractDocument
    {
        return $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'contract.pdf',
            'file_path' => 'contracts/contract.pdf',
        ]);
    }

    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    private function queryException(array $errorInfo): QueryException
    {
        $previous = new PDOException($errorInfo[2]);
        $previous->errorInfo = $errorInfo;

        return new QueryException(
            'testing',
            'delete from contracts where id = ?',
            [1],
            $previous
        );
    }
}
