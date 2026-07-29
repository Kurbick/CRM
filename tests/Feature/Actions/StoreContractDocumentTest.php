<?php

namespace Tests\Feature\Actions;

use App\Actions\ContractDocuments\StoreContractDocument;
use App\Exceptions\ContractDocumentStorageException;
use App\Models\ContractDocument;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Authorization\AuthorizationTestCase;

class StoreContractDocumentTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_success_creates_one_row_and_one_private_file_with_safe_generated_path(): void
    {
        $contract = $this->contract($this->company());
        $file = UploadedFile::fake()->create('customer-agreement.pdf', 4, 'application/pdf');

        $document = app(StoreContractDocument::class)->handle($contract, $file, 'signed', 'Stored safely');

        $this->assertDatabaseHas('contract_documents', [
            'id' => $document->id,
            'contract_id' => $contract->id,
            'original_name' => 'customer-agreement.pdf',
            'comment' => 'Stored safely',
        ]);
        $this->assertMatchesRegularExpression(
            '#^contract-documents/'.$contract->id.'/[0-9a-f-]{36}\.pdf$#',
            $document->file_path
        );
        $this->assertStringNotContainsString('customer-agreement', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_storage_write_failure_creates_no_row_and_preserves_safe_previous(): void
    {
        $contract = $this->contract($this->company());
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->twice()->andReturn(false);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->once()->andReturn($disk);

        try {
            (new StoreContractDocument($manager))->handle(
                $contract,
                UploadedFile::fake()->create('failure.pdf', 4, 'application/pdf'),
                'signed'
            );
            $this->fail('Storage failure was not converted.');
        } catch (ContractDocumentStorageException $exception) {
            $this->assertSame('Не удалось сохранить документ.', $exception->getMessage());
            $this->assertStringNotContainsString('contract-documents', $exception->getMessage());
        }

        $this->assertDatabaseCount('contract_documents', 0);
    }

    public function test_database_failure_after_write_removes_file_and_keeps_original_exception_as_previous(): void
    {
        $contract = $this->contract($this->company());
        $queryException = $this->queryException();
        ContractDocument::creating(fn () => throw $queryException);

        try {
            app(StoreContractDocument::class)->handle(
                $contract,
                UploadedFile::fake()->create('database.pdf', 4, 'application/pdf'),
                'signed'
            );
            $this->fail('Database failure was not converted.');
        } catch (ContractDocumentStorageException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
            $this->assertStringNotContainsString('contract-documents', $exception->getMessage());
        }

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_cleanup_failure_is_logged_without_losing_original_database_exception(): void
    {
        $contract = $this->contract($this->company());
        $queryException = $this->queryException();
        ContractDocument::creating(fn () => throw $queryException);
        $files = [];
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(
            function (string $directory, mixed $file, string $filename) use (&$files): string {
                $path = $directory.'/'.$filename;
                $files[$path] = true;

                return $path;
            }
        );
        $disk->shouldReceive('exists')->andReturnUsing(
            function (string $path) use (&$files): bool {
                return $files[$path] ?? false;
            }
        );
        $disk->shouldReceive('delete')->once()->with(Mockery::type('string'))->andReturn(false);
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->once()->andReturn($disk);
        Log::shouldReceive('critical')
            ->once()
            ->with('Contract document upload compensation failed.', Mockery::on(
                fn (array $context): bool => $context['contract_id'] === $contract->id
                    && $context['disk'] === 'local'
                    && str_starts_with($context['relative_path'], "contract-documents/{$contract->id}/")
                    && str_ends_with($context['relative_path'], '.pdf')
                    && $context['reason'] === 'upload_compensation_delete_returned_false'
                    && $context['database_exception'] === $queryException
                    && $context['cleanup_exception'] === null
            ));

        try {
            (new StoreContractDocument($manager))->handle(
                $contract,
                UploadedFile::fake()->create('cleanup.pdf', 4, 'application/pdf'),
                'signed'
            );
            $this->fail('Database failure was not converted.');
        } catch (ContractDocumentStorageException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
        }

        $this->assertDatabaseCount('contract_documents', 0);
        $this->assertCount(1, array_filter($files));
    }

    public function test_cleanup_exception_is_logged_without_replacing_database_failure(): void
    {
        $contract = $this->contract($this->company());
        $queryException = $this->queryException();
        $cleanupException = new \RuntimeException('Synthetic cleanup failure');
        ContractDocument::creating(fn () => throw $queryException);
        $files = [];
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(
            function (string $directory, mixed $file, string $filename) use (&$files): string {
                $path = $directory.'/'.$filename;
                $files[$path] = true;

                return $path;
            }
        );
        $disk->shouldReceive('exists')->andReturnUsing(
            function (string $path) use (&$files): bool {
                return $files[$path] ?? false;
            }
        );
        $disk->shouldReceive('delete')->once()->andThrow($cleanupException);
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->once()->andReturn($disk);
        Log::shouldReceive('critical')->once()->with(
            'Contract document upload compensation failed.',
            Mockery::on(fn (array $context): bool => $context['contract_id'] === $contract->id
                && $context['disk'] === 'local'
                && $context['reason'] === 'upload_compensation_delete_threw'
                && $context['database_exception'] === $queryException
                && $context['cleanup_exception'] === $cleanupException)
        );

        try {
            (new StoreContractDocument($manager))->handle(
                $contract,
                UploadedFile::fake()->create('cleanup-exception.pdf', 4, 'application/pdf'),
                'signed'
            );
            $this->fail('Database failure was not converted.');
        } catch (ContractDocumentStorageException $exception) {
            $this->assertSame($queryException, $exception->getPrevious());
        }

        $this->assertDatabaseCount('contract_documents', 0);
        $this->assertCount(1, array_filter($files));
    }

    public function test_uuid_collision_retries_without_overwriting_existing_file(): void
    {
        $contract = $this->contract($this->company());
        $collisionUuid = Uuid::fromString('00000000-0000-4000-8000-000000000001');
        $availableUuid = Uuid::fromString('00000000-0000-4000-8000-000000000002');
        $collisionPath = "contract-documents/{$contract->id}/{$collisionUuid}.pdf";
        Storage::disk('local')->put($collisionPath, 'EXISTING-CONTENT');
        Str::createUuidsUsingSequence([$collisionUuid, $availableUuid]);

        try {
            $document = app(StoreContractDocument::class)->handle(
                $contract,
                UploadedFile::fake()->create('collision.pdf', 4, 'application/pdf'),
                'signed'
            );
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame('EXISTING-CONTENT', Storage::disk('local')->get($collisionPath));
        $this->assertSame(
            "contract-documents/{$contract->id}/{$availableUuid}.pdf",
            $document->file_path
        );
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id, 'file_path' => $document->file_path]);
    }

    public function test_unexpected_programming_exception_is_rethrown_after_compensation(): void
    {
        $contract = $this->contract($this->company());
        $programmingException = new \LogicException('Synthetic programming error');
        ContractDocument::creating(fn () => throw $programmingException);

        try {
            app(StoreContractDocument::class)->handle(
                $contract,
                UploadedFile::fake()->create('programming.pdf', 4, 'application/pdf'),
                'signed'
            );
            $this->fail('Programming exception was masked.');
        } catch (\LogicException $exception) {
            $this->assertSame($programmingException, $exception);
        }

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    private function queryException(): QueryException
    {
        $previous = new PDOException('Synthetic database failure');
        $previous->errorInfo = ['HY000', 1, 'Synthetic database failure'];

        return new QueryException('testing', 'insert into contract_documents values (?)', ['secret'], $previous);
    }
}
