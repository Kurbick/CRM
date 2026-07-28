<?php

namespace Tests\Feature\Actions;

use App\Actions\Companies\CompanyDeletionDependencies;
use App\Actions\Companies\DeleteCompany;
use App\Exceptions\CompanyDeletionException;
use App\Models\Company;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class DeleteCompanyTest extends AuthorizationTestCase
{
    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    #[DataProvider('foreignKeyErrorInfoProvider')]
    public function test_foreign_key_query_exception_is_wrapped_and_model_event_changes_are_rolled_back(
        array $errorInfo
    ): void {
        $company = $this->company('ROLLBACK-FK-'.uniqid());
        $contact = $this->contact($company, 'ROLLBACK-CONTACT');
        $queryException = $this->queryException($errorInfo);
        $dependencies = Mockery::mock(CompanyDeletionDependencies::class);
        $dependencies->shouldReceive('hasBlockingDependencies')->once()->andReturnFalse();
        $deletingEventReached = false;
        $contactWasDeletedBeforeFailure = false;

        Company::deleting(function (Company $deletingCompany) use (
            $company,
            $contact,
            $queryException,
            &$deletingEventReached,
            &$contactWasDeletedBeforeFailure
        ): void {
            if ($deletingCompany->is($company)) {
                $deletingEventReached = true;
                $contactWasDeletedBeforeFailure = ! DB::table('company_contacts')
                    ->where('id', $contact->id)
                    ->exists();
                throw $queryException;
            }
        });

        try {
            (new DeleteCompany($dependencies))->handle($company);
            $this->fail('The foreign key violation was not converted.');
        } catch (CompanyDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }

        $this->assertTrue($deletingEventReached);
        $this->assertTrue($contactWasDeletedBeforeFailure);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('company_contacts', [
            'id' => $contact->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_non_foreign_key_query_exception_is_rethrown_unchanged(): void
    {
        $company = $this->company('RETHROW-NON-FK-'.uniqid());
        $queryException = $this->queryException([
            '23000',
            1062,
            "Duplicate entry 'value' for key 'companies_name_unique'",
        ]);
        $dependencies = Mockery::mock(CompanyDeletionDependencies::class);
        $dependencies->shouldReceive('hasBlockingDependencies')->once()->andReturnFalse();

        Company::deleting(function (Company $deletingCompany) use ($company, $queryException): void {
            if ($deletingCompany->is($company)) {
                throw $queryException;
            }
        });

        try {
            (new DeleteCompany($dependencies))->handle($company);
            $this->fail('The non-foreign-key query exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_payment_schema_requires_invoice_and_normal_payment_matches_invoice_company(): void
    {
        $invoiceIdColumn = collect(Schema::getColumns('payments'))
            ->firstWhere('name', 'invoice_id');
        $company = $this->company('Payment schema company');

        $this->assertNotNull($invoiceIdColumn);
        $this->assertFalse($invoiceIdColumn['nullable']);

        try {
            DB::table('payments')->insert([
                'invoice_id' => null,
                'company_id' => $company->id,
                'payment_date' => '2026-07-15',
                'amount' => '10.00',
                'payment_method' => 'transfer',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A payment without an invoice was inserted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $orphanInvoiceId = ((int) DB::table('invoices')->max('id')) + 1_000_000;

        try {
            DB::table('payments')->insert([
                'invoice_id' => $orphanInvoiceId,
                'company_id' => $company->id,
                'payment_date' => '2026-07-15',
                'amount' => '11.00',
                'payment_method' => 'transfer',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A payment with an orphan invoice ID was inserted.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('payments', [
                'invoice_id' => $orphanInvoiceId,
                'company_id' => $company->id,
            ]);
        }

        $invoice = $this->invoice('issued', 'PAYMENT-SCHEMA');
        $payment = Payment::withoutEvents(fn () => Payment::query()->create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'payment_date' => '2026-07-15',
            'amount' => '10.00',
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]));

        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame($invoice->company_id, $payment->company_id);
        $this->assertTrue($payment->invoice->is($invoice));
    }

    public static function foreignKeyErrorInfoProvider(): array
    {
        return [
            'PostgreSQL' => [['23503', 7, 'update or delete violates foreign key constraint']],
            'MySQL or MariaDB' => [['23000', 1451, 'Cannot delete or update a parent row']],
            'SQLite' => [['23000', 19, 'FOREIGN KEY constraint failed']],
        ];
    }

    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    private function queryException(array $errorInfo): QueryException
    {
        $previous = new PDOException($errorInfo[2]);
        $previous->errorInfo = $errorInfo;

        return new QueryException(
            'testing',
            'delete from companies where id = ?',
            [1],
            $previous
        );
    }
}
