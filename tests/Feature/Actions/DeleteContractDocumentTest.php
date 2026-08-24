<?php

namespace Tests\Feature\Actions;

use App\Actions\ContractDocuments\DeleteContractDocument;
use App\Exceptions\ContractDocumentDeletionException;
use App\Models\ContractDocument;
use App\Services\CompanyActivityRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use Tests\Feature\Authorization\AuthorizationTestCase;

class DeleteContractDocumentTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_success_moves_then_deletes_metadata_and_quarantine_file(): void
    {
        $document = $this->document(true);

        app(DeleteContractDocument::class)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('contract-documents/.quarantine'));
    }

    public function test_missing_physical_file_deletes_stale_metadata_and_logs_warning(): void
    {
        $document = $this->document(false);
        Log::shouldReceive('warning')->once()->with(
            'Contract document physical file is missing.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && $context['quarantine_relative_path'] === null
                && $context['reason'] === 'physical_file_missing')
        );

        app(DeleteContractDocument::class)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
    }

    public function test_database_failure_after_sql_delete_rolls_back_row_and_parent_change_and_restores_file(): void
    {
        $document = $this->document(true);
        $contract = $document->contract;
        $contract->update(['comment' => $originalComment = 'DOCUMENT-ROLLBACK-ORIGINAL']);
        $queryException = $this->queryException();
        $originalContent = Storage::disk('local')->get($document->file_path);
        $quarantinePath = null;
        $deletedEventReached = false;
        $rowWasDeleted = false;
        $parentWasChanged = false;
        ContractDocument::deleted(function (ContractDocument $deleted) use (
            $document,
            $contract,
            $queryException,
            &$deletedEventReached,
            &$rowWasDeleted,
            &$parentWasChanged,
            &$quarantinePath,
            $originalContent
        ): void {
            if (! $deleted->is($document)) {
                return;
            }

            $deletedEventReached = true;
            $rowWasDeleted = ! ContractDocument::query()->whereKey($document->id)->exists();
            $this->assertFalse(Storage::disk('local')->exists($document->file_path));
            $quarantineFiles = Storage::disk('local')->allFiles('contract-documents/.quarantine');
            $this->assertCount(1, $quarantineFiles);
            $quarantinePath = $quarantineFiles[0];
            Storage::disk('local')->assertExists($quarantinePath);
            $this->assertSame($originalContent, Storage::disk('local')->get($quarantinePath));
            DB::table('contracts')->where('id', $contract->id)->update(['comment' => 'DOCUMENT-ROLLBACK-MARKER']);
            $parentWasChanged = DB::table('contracts')->where('id', $contract->id)->value('comment') === 'DOCUMENT-ROLLBACK-MARKER';
            throw $queryException;
        });

        try {
            app(DeleteContractDocument::class)->handle($document);
            $this->fail('Database failure was not converted.');
        } catch (ContractDocumentDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
            $this->assertStringNotContainsString($document->file_path, $exception->getMessage());
        }

        $this->assertTrue($deletedEventReached);
        $this->assertTrue($rowWasDeleted);
        $this->assertTrue($parentWasChanged);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'comment' => $originalComment]);
        $this->assertDatabaseMissing('contracts', ['id' => $contract->id, 'comment' => 'DOCUMENT-ROLLBACK-MARKER']);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame($originalContent, Storage::disk('local')->get($document->file_path));
        $this->assertNotNull($quarantinePath);
        Storage::disk('local')->assertMissing($quarantinePath);
        $this->assertSame([], Storage::disk('local')->allFiles('contract-documents/.quarantine'));
    }

    public function test_deleted_listener_from_rollback_test_does_not_leak(): void
    {
        $document = $this->document(true);

        app(DeleteContractDocument::class)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
    }

    public function test_quarantine_move_failure_preserves_row_and_original_file(): void
    {
        $document = $this->document(false);
        $files = [$document->file_path => true];
        $disk = $this->mockDisk($files);
        $disk->shouldReceive('move')->once()->andReturn(false);

        try {
            $this->action($disk)->handle($document);
            $this->fail('Move failure was not converted.');
        } catch (ContractDocumentDeletionException $exception) {
            $this->assertSame('Не удалось удалить документ.', $exception->getMessage());
        }

        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        $this->assertTrue($files[$document->file_path]);
        $this->assertCount(1, array_filter($files));
    }

    public function test_restore_failure_keeps_rolled_back_row_and_quarantine_and_logs_critical(): void
    {
        $document = $this->document(false);
        $queryException = $this->queryException();
        $restoreException = new \RuntimeException('Synthetic restore failure');
        ContractDocument::deleted(fn (ContractDocument $deleted) => $deleted->is($document) ? throw $queryException : null);
        $files = [$document->file_path => true];
        $disk = $this->mockDisk($files);
        $moveCalls = 0;
        $disk->shouldReceive('move')->twice()->andReturnUsing(function (string $from, string $to) use (&$files, &$moveCalls, $restoreException): bool {
            $moveCalls++;
            if ($moveCalls === 1) {
                $files[$from] = false;
                $files[$to] = true;

                return true;
            }

            throw $restoreException;
        });
        Log::shouldReceive('critical')->once()->with(
            'Contract document quarantine restore failed.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && str_starts_with($context['quarantine_relative_path'], 'contract-documents/.quarantine/')
                && $context['reason'] === 'rollback_restore_failed'
                && $context['database_exception'] === $queryException
                && $context['restore_exception'] === $restoreException)
        );

        try {
            $this->action($disk)->handle($document);
            $this->fail('Database failure was not converted.');
        } catch (ContractDocumentDeletionException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
        }

        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        $this->assertFalse($files[$document->file_path]);
        $this->assertCount(1, array_filter($files));
    }

    public function test_post_commit_cleanup_failure_is_success_with_inaccessible_quarantine_and_critical_log(): void
    {
        $document = $this->document(false);
        $files = [$document->file_path => true];
        $disk = $this->mockDisk($files);
        $disk->shouldReceive('move')->once()->andReturnUsing(function (string $from, string $to) use (&$files): bool {
            $files[$from] = false;
            $files[$to] = true;

            return true;
        });
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Log::shouldReceive('critical')->once()->with(
            'Contract document quarantine cleanup failed.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && str_starts_with($context['quarantine_relative_path'], 'contract-documents/.quarantine/')
                && $context['reason'] === 'post_commit_delete_returned_false'
                && $context['exception'] === null)
        );

        $this->action($disk)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        $this->assertFalse($files[$document->file_path]);
        $this->assertCount(1, array_filter($files));
    }

    public function test_post_commit_cleanup_exception_is_success_and_logs_exception_context(): void
    {
        $document = $this->document(false);
        $cleanupException = new \RuntimeException('Synthetic post-commit delete failure');
        $files = [$document->file_path => true];
        $disk = $this->mockDisk($files);
        $disk->shouldReceive('move')->once()->andReturnUsing(function (string $from, string $to) use (&$files): bool {
            $files[$from] = false;
            $files[$to] = true;

            return true;
        });
        $disk->shouldReceive('delete')->once()->andThrow($cleanupException);
        Log::shouldReceive('critical')->once()->with(
            'Contract document quarantine cleanup failed.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && str_starts_with($context['quarantine_relative_path'], 'contract-documents/.quarantine/')
                && $context['reason'] === 'post_commit_quarantine_delete_failed'
                && $context['exception'] === $cleanupException)
        );

        $this->action($disk)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        $this->assertFalse($files[$document->file_path]);
        $this->assertCount(1, array_filter($files));
    }

    public function test_already_deleted_row_is_idempotent_and_does_not_touch_storage(): void
    {
        $document = $this->document(false);
        $document->delete();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldNotReceive('exists', 'move', 'delete');

        $this->action($disk)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
    }

    public function test_shared_legacy_path_deletes_only_metadata_and_preserves_file_for_other_row(): void
    {
        $document = $this->document(true);
        $other = $document->contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'shared.pdf',
            'file_path' => $document->file_path,
        ]);
        Log::shouldReceive('warning')->once()->with(
            'Contract document path is shared by legacy metadata.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && $context['quarantine_relative_path'] === null
                && $context['reason'] === 'shared_legacy_path')
        );

        app(DeleteContractDocument::class)->handle($document);

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('contract_documents', ['id' => $other->id]);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_unsafe_legacy_path_cannot_delete_metadata_or_unrelated_file(): void
    {
        $contract = $this->contract($this->company());
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'unsafe.pdf',
            'file_path' => '../outside-document.pdf',
        ]);
        Storage::disk('local')->put('outside-document.pdf', 'UNRELATED-CONTENT');
        Log::shouldReceive('warning')->once()->with(
            'Contract document path is unsafe.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $document->contract_id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === '../outside-document.pdf'
                && $context['quarantine_relative_path'] === null
                && $context['reason'] === 'unsafe_document_path')
        );

        $this->expectException(ContractDocumentDeletionException::class);

        try {
            app(DeleteContractDocument::class)->handle($document);
        } finally {
            $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
            Storage::disk('local')->assertExists('outside-document.pdf');
            $this->assertSame('UNRELATED-CONTENT', Storage::disk('local')->get('outside-document.pdf'));
        }
    }

    private function document(bool $write): ContractDocument
    {
        $contract = $this->contract($this->company('Delete document company '.uniqid()));
        $path = "contract-documents/{$contract->id}/".uniqid().'.pdf';
        if ($write) {
            Storage::disk('local')->put($path, 'DOCUMENT-CONTENT');
        }

        return $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'document.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
        ]);
    }

    /** @param array<string, bool> $files */
    private function mockDisk(array &$files): FilesystemAdapter
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnUsing(
            function (string $path) use (&$files): bool {
                return $files[$path] ?? false;
            }
        );

        return $disk;
    }

    private function action(FilesystemAdapter $disk): DeleteContractDocument
    {
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->once()->andReturn($disk);

        return new DeleteContractDocument($manager, app(CompanyActivityRecorder::class));
    }

    private function queryException(): QueryException
    {
        $previous = new PDOException('Synthetic database failure');
        $previous->errorInfo = ['23000', 19, 'FOREIGN KEY constraint failed'];

        return new QueryException('testing', 'delete from contract_documents where id = ?', [1], $previous);
    }
}
