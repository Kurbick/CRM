<?php

namespace App\Actions\ContractDocuments;

use App\Exceptions\ContractDocumentStorageException;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Support\ContractDocuments\ContractDocumentFileType;
use App\Support\ContractDocuments\ContractDocumentPath;
use App\Support\ContractDocuments\SafeDocumentName;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Throwable;

final class StoreContractDocument
{
    private const EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    private const PATH_GENERATION_ATTEMPTS = 5;

    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function handle(
        Contract $contract,
        UploadedFile $file,
        string $documentType,
        ?string $comment = null
    ): ContractDocument {
        $disk = $this->filesystems->disk(ContractDocumentPath::DISK);
        $extension = ContractDocumentFileType::serverExtension($file);

        if ($extension === null || ! in_array($extension, self::EXTENSIONS, true)) {
            throw ContractDocumentStorageException::writeFailed();
        }

        $directory = "contract-documents/{$contract->getKey()}";
        $path = null;

        try {
            [$filename, $path] = $this->availablePath($disk, $directory, $extension);
            $stored = $disk->putFileAs($directory, $file, $filename);

            if ($stored !== $path || ! $disk->exists($path)) {
                throw ContractDocumentStorageException::writeFailed();
            }

            return DB::transaction(function () use ($contract, $file, $documentType, $comment, $path): ContractDocument {
                $lockedContract = Contract::query()->lockForUpdate()->findOrFail($contract->getKey());

                return $lockedContract->documents()->create([
                    'document_type' => $documentType,
                    'original_name' => SafeDocumentName::sanitize($file->getClientOriginalName()),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'comment' => $comment,
                ]);
            });
        } catch (ContractDocumentStorageException $exception) {
            $this->cleanUpWrittenFile($disk, $path, $contract->getKey(), $exception);

            throw $exception;
        } catch (QueryException $exception) {
            $this->cleanUpWrittenFile($disk, $path, $contract->getKey(), $exception);

            throw ContractDocumentStorageException::databaseFailed($exception);
        } catch (FilesystemException $exception) {
            $this->cleanUpWrittenFile($disk, $path, $contract->getKey(), $exception);

            throw ContractDocumentStorageException::writeFailed($exception);
        } catch (Throwable $exception) {
            $this->cleanUpWrittenFile($disk, $path, $contract->getKey(), $exception);

            throw $exception;
        }
    }

    /** @return array{string, string} */
    private function availablePath(mixed $disk, string $directory, string $extension): array
    {
        for ($attempt = 0; $attempt < self::PATH_GENERATION_ATTEMPTS; $attempt++) {
            $filename = Str::uuid().'.'.$extension;
            $path = $directory.'/'.$filename;

            if (! $disk->exists($path)) {
                return [$filename, $path];
            }
        }

        throw ContractDocumentStorageException::writeFailed();
    }

    private function cleanUpWrittenFile(
        mixed $disk,
        ?string $path,
        int|string $contractId,
        Throwable $operationException
    ): void {
        if ($path === null) {
            return;
        }

        try {
            if (! $disk->exists($path)) {
                return;
            }

            if (! $disk->delete($path)) {
                Log::critical('Contract document upload compensation failed.', [
                    'contract_id' => $contractId,
                    'disk' => ContractDocumentPath::DISK,
                    'relative_path' => $path,
                    'reason' => 'upload_compensation_delete_returned_false',
                    'database_exception' => $operationException instanceof QueryException
                        ? $operationException
                        : null,
                    'cleanup_exception' => null,
                ]);

                return;
            }

            if ($disk->exists($path)) {
                Log::critical('Contract document upload compensation failed.', [
                    'contract_id' => $contractId,
                    'disk' => ContractDocumentPath::DISK,
                    'relative_path' => $path,
                    'reason' => 'upload_compensation_file_still_exists',
                    'database_exception' => $operationException instanceof QueryException
                        ? $operationException
                        : null,
                    'cleanup_exception' => null,
                ]);
            }
        } catch (Throwable $cleanupException) {
            Log::critical('Contract document upload compensation failed.', [
                'contract_id' => $contractId,
                'disk' => ContractDocumentPath::DISK,
                'relative_path' => $path,
                'reason' => 'upload_compensation_delete_threw',
                'database_exception' => $operationException instanceof QueryException
                    ? $operationException
                    : null,
                'cleanup_exception' => $cleanupException,
            ]);
        }
    }
}
