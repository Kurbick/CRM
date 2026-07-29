<?php

namespace Tests\Feature\Actions;

use App\Actions\Subscriptions\DeleteSubscription;
use App\Exceptions\SubscriptionDeletionException;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class DeleteSubscriptionTest extends AuthorizationTestCase
{
    public function test_can_delete_and_handle_delete_only_unused_subscription(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));
        $action = app(DeleteSubscription::class);
        $this->assertTrue($action->canDelete($subscription));
        $action->handle($subscription);
        $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);
    }

    public function test_invoice_line_blocks_delete_and_preserves_financial_records(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));
        $chain = $this->subjectFinancialChain($subscription);
        $action = app(DeleteSubscription::class);
        $this->assertFalse($action->canDelete($subscription));

        try {
            $action->handle($subscription);
            $this->fail('Invoiced subscription was deleted.');
        } catch (SubscriptionDeletionException $exception) {
            $this->assertSame('Невозможно удалить подписку, поскольку она уже используется в инвойсе.', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
        $this->assertSubjectFinancialChainExists($subscription, $chain);
    }

    #[DataProvider('invoiceStatusProvider')]
    public function test_invoice_line_blocks_subscription_delete_regardless_of_invoice_and_subscription_status(string $invoiceStatus, string $subscriptionStatus): void
    {
        $subscription = $this->subjectSubscription($contract = $this->contract($this->company()), ['status' => $subscriptionStatus]);
        $invoice = $contract->invoices()->create([
            'company_id' => $contract->company_id,
            'invoice_number' => 'SUBSCRIPTION-STATUS-'.uniqid(),
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-31',
            'total_amount' => 100,
            'status' => $invoiceStatus,
        ]);
        $line = $invoice->lines()->create([
            'subscription_id' => $subscription->id,
            'description' => 'Snapshot',
            'amount' => 100,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);

        $this->expectException(SubscriptionDeletionException::class);

        try {
            app(DeleteSubscription::class)->handle($subscription);
        } finally {
            $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
            $this->assertDatabaseHas('invoice_lines', ['id' => $line->id]);
        }
    }

    public function test_fk_failure_after_two_sql_changes_rolls_back_subscription_and_contract(): void
    {
        $contract = $this->contract($this->company());
        $contract->update(['comment' => $originalComment = 'SUBSCRIPTION-ROLLBACK-ORIGINAL-'.uniqid()]);
        $subscription = $this->subjectSubscription($contract);
        $rollbackMarker = 'SUBSCRIPTION-ROLLBACK-MARKER-'.uniqid();
        $queryException = $this->queryException(['23000', 19, 'FOREIGN KEY constraint failed']);
        $deletedEventReached = false;
        $rowWasDeleted = false;
        $contractWasUpdated = false;
        Subscription::deleted(function (Subscription $deleted) use ($subscription, $contract, $rollbackMarker, $queryException, &$deletedEventReached, &$rowWasDeleted, &$contractWasUpdated): void {
            if ($deleted->is($subscription)) {
                $deletedEventReached = true;
                $rowWasDeleted = ! Subscription::query()->whereKey($subscription->id)->exists();
                DB::table('contracts')->where('id', $contract->id)->update(['comment' => $rollbackMarker]);
                $contractWasUpdated = DB::table('contracts')->where('id', $contract->id)->value('comment') === $rollbackMarker;
                throw $queryException;
            }
        });

        try {
            app(DeleteSubscription::class)->handle($subscription);
            $this->fail('FK failure was not wrapped.');
        } catch (SubscriptionDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('delete from', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }
        $this->assertTrue($deletedEventReached);
        $this->assertTrue($rowWasDeleted);
        $this->assertTrue($contractWasUpdated);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'comment' => $originalComment]);
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id, 'comment' => $rollbackMarker]);
    }

    #[DataProvider('foreignKeyProvider')]
    public function test_supported_fk_failures_are_wrapped_with_previous(array $errorInfo): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));
        $queryException = $this->queryException($errorInfo);
        Subscription::deleted(fn (Subscription $deleted) => $deleted->is($subscription) ? throw $queryException : null);

        try {
            app(DeleteSubscription::class)->handle($subscription);
            $this->fail('FK failure was not wrapped.');
        } catch (SubscriptionDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    public function test_non_fk_query_exception_is_rethrown_unchanged_and_rolled_back(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));
        $queryException = $this->queryException(['23000', 1062, 'Duplicate entry']);
        Subscription::deleted(fn (Subscription $deleted) => $deleted->is($subscription) ? throw $queryException : null);
        try {
            app(DeleteSubscription::class)->handle($subscription);
            $this->fail('Non-FK exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    #[DataProvider('nonForeignKeyProvider')]
    public function test_sqlite_unique_and_incomplete_error_info_are_rethrown_unchanged(?array $errorInfo): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));
        $queryException = $this->queryException($errorInfo);
        Subscription::deleted(fn (Subscription $deleted) => $deleted->is($subscription) ? throw $queryException : null);

        try {
            app(DeleteSubscription::class)->handle($subscription);
            $this->fail('Non-FK exception was masked.');
        } catch (QueryException $exception) {
            $this->assertSame($queryException, $exception);
        }

        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    public function test_temporary_deleted_listener_does_not_leak_into_next_test_case(): void
    {
        $subscription = $this->subjectSubscription($this->contract($this->company()));

        app(DeleteSubscription::class)->handle($subscription);

        $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);
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
            'draft invoice and cancelled subscription' => ['draft', 'cancelled'],
            'cancelled invoice and completed subscription' => ['cancelled', 'completed'],
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

        return new QueryException('testing', 'delete from subscriptions where id = ?', [1], $previous);
    }
}
