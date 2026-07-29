<?php

namespace Tests\Feature\Actions;

use App\Actions\Orders\DeleteOrder;
use App\Exceptions\OrderDeletionException;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class DeleteOrderTest extends AuthorizationTestCase
{
    public function test_can_delete_and_handle_delete_only_unused_order(): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $action = app(DeleteOrder::class);
        $this->assertTrue($action->canDelete($order));
        $action->handle($order);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_invoice_line_blocks_can_delete_and_handle_without_deleting_financial_records(): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $chain = $this->subjectFinancialChain($order);
        $action = app(DeleteOrder::class);
        $this->assertFalse($action->canDelete($order));

        try {
            $action->handle($order);
            $this->fail('Invoiced order was deleted.');
        } catch (OrderDeletionException $exception) {
            $this->assertSame('Невозможно удалить разовую услугу, поскольку она уже используется в инвойсе.', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
        $this->assertSubjectFinancialChainExists($order, $chain);
    }

    #[DataProvider('invoiceStatusProvider')]
    public function test_invoice_line_blocks_order_delete_regardless_of_invoice_and_order_status(string $invoiceStatus, string $orderStatus): void
    {
        $order = $this->subjectOrder($contract = $this->contract($this->company()), ['status' => $orderStatus]);
        $invoice = $contract->invoices()->create([
            'company_id' => $contract->company_id,
            'invoice_number' => 'ORDER-STATUS-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => 100,
            'status' => $invoiceStatus,
        ]);
        $line = $invoice->lines()->create(['order_id' => $order->id, 'description' => 'Snapshot', 'amount' => 100]);

        $this->expectException(OrderDeletionException::class);

        try {
            app(DeleteOrder::class)->handle($order);
        } finally {
            $this->assertDatabaseHas('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
        }
    }

    public function test_fk_failure_after_two_sql_changes_rolls_back_order_and_contract(): void
    {
        $contract = $this->contract($this->company());
        $contract->update(['comment' => $originalComment = 'ORDER-ROLLBACK-ORIGINAL-'.uniqid()]);
        $order = $this->subjectOrder($contract);
        $rollbackMarker = 'ORDER-ROLLBACK-MARKER-'.uniqid();
        $queryException = $this->queryException(['23000', 19, 'FOREIGN KEY constraint failed']);
        $deletedEventReached = false;
        $rowWasDeleted = false;
        $contractWasUpdated = false;
        Order::deleted(function (Order $deleted) use ($order, $contract, $rollbackMarker, $queryException, &$deletedEventReached, &$rowWasDeleted, &$contractWasUpdated): void {
            if ($deleted->is($order)) {
                $deletedEventReached = true;
                $rowWasDeleted = ! Order::query()->whereKey($order->id)->exists();
                DB::table('contracts')->where('id', $contract->id)->update(['comment' => $rollbackMarker]);
                $contractWasUpdated = DB::table('contracts')->where('id', $contract->id)->value('comment') === $rollbackMarker;
                throw $queryException;
            }
        });

        try {
            app(DeleteOrder::class)->handle($order);
            $this->fail('FK failure was not wrapped.');
        } catch (OrderDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('delete from', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }

        $this->assertTrue($deletedEventReached);
        $this->assertTrue($rowWasDeleted);
        $this->assertTrue($contractWasUpdated);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'comment' => $originalComment]);
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id, 'comment' => $rollbackMarker]);
    }

    #[DataProvider('foreignKeyProvider')]
    public function test_supported_fk_failures_are_wrapped_with_previous(array $errorInfo): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $queryException = $this->queryException($errorInfo);
        Order::deleted(fn (Order $deleted) => $deleted->is($order) ? throw $queryException : null);

        try {
            app(DeleteOrder::class)->handle($order);
            $this->fail('FK failure was not wrapped.');
        } catch (OrderDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_non_fk_query_exception_is_rethrown_unchanged_and_rolled_back(): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $queryException = $this->queryException(['23000', 1062, 'Duplicate entry']);
        Order::deleted(fn (Order $deleted) => $deleted->is($order) ? throw $queryException : null);

        try {
            app(DeleteOrder::class)->handle($order);
            $this->fail('Non-FK exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    #[DataProvider('nonForeignKeyProvider')]
    public function test_sqlite_unique_and_incomplete_error_info_are_rethrown_unchanged(?array $errorInfo): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));
        $queryException = $this->queryException($errorInfo);
        Order::deleted(fn (Order $deleted) => $deleted->is($order) ? throw $queryException : null);

        try {
            app(DeleteOrder::class)->handle($order);
            $this->fail('Non-FK exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_temporary_deleted_listener_does_not_leak_into_next_test_case(): void
    {
        $order = $this->subjectOrder($this->contract($this->company()));

        app(DeleteOrder::class)->handle($order);

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public static function foreignKeyProvider(): array
    {
        return [
            'PostgreSQL' => [['23503', 7, 'foreign key violation']],
            'MySQL' => [['23000', 1451, 'Cannot delete parent']],
            'MariaDB insert-side FK' => [['23000', 1452, 'Cannot add child']],
            'MySQL string driver code' => [['23000', '1451', 'Cannot delete parent']],
            'SQLite code 19' => [['23000', 19, 'FOREIGN KEY constraint failed']],
            'SQLite code 787' => [['23000', 787, 'FOREIGN KEY constraint failed']],
        ];
    }

    public static function invoiceStatusProvider(): array
    {
        return [
            'draft invoice and cancelled order' => ['draft', 'cancelled'],
            'cancelled invoice and completed order' => ['cancelled', 'completed'],
        ];
    }

    public static function nonForeignKeyProvider(): array
    {
        return [
            'SQLite unique constraint' => [['23000', 19, 'UNIQUE constraint failed: example.column']],
            'null error info' => [null],
            'empty error info' => [[]],
            'SQLSTATE only' => [['HY000']],
            'non-FK string driver code' => [['23000', '1062', 'Duplicate entry']],
        ];
    }

    private function queryException(?array $errorInfo): QueryException
    {
        $previous = new PDOException((string) ($errorInfo[2] ?? 'Synthetic database error'));
        if ($errorInfo !== null) {
            $previous->errorInfo = $errorInfo;
        }

        return new QueryException('testing', 'delete from orders where id = ?', [1], $previous);
    }
}
