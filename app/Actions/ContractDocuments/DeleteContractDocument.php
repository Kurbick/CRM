<?php

namespace App\Actions\ContractDocuments;

use App\Exceptions\ContractDocumentDeletionException;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\User;
use App\Services\CompanyActivityRecorder;
use App\Support\CompanyActivityCategory;
use App\Support\CompanyActivityEventType;
use App\Support\CompanyActivitySnapshot;
use App\Support\CompanyActivityVisibilityScope;
use App\Support\ContractDocuments\ContractDocumentPath;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use Throwable;

final class DeleteContractDocument
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly CompanyActivityRecorder $activityRecorder,
    ) {}

    public function handle(ContractDocument $document, ?User $actor = null): void
    {
        $disk = $this->filesystems->disk(ContractDocumentPath::DISK);
        $documentId = $document->getKey();
        $contractId = null;
        $originalPath = null;
        $quarantinePath = null;
        $fileWasMoved = false;

        try {
            DB::transaction(function () use (
                $disk,
                $documentId,
                &$contractId,
                &$originalPath,
                &$quarantinePath,
                &$fileWasMoved,
                $actor
            ): void {
                $locked = ContractDocument::query()->lockForUpdate()->find($documentId);

                if ($locked === null) {
                    return;
                }

                $contractId = $locked->contract_id;
                $originalPath = (string) $locked->file_path;
                $contract = $locked->contract()
                    ->select(['id', 'company_id', 'contract_number'])
                    ->firstOrFail();
                $metadata = [
                    'document_name' => $locked->original_name,
                    'contract_number' => $contract->contract_number,
                    'document_type' => $locked->document_type,
                ];

                if (! ContractDocumentPath::isAllowed($locked, $originalPath)) {
                    Log::warning('Contract document path is unsafe.', $this->logContext(
                        $documentId,
                        $contractId,
                        $originalPath,
                        null,
                        'unsafe_document_path'
                    ));

                    throw ContractDocumentDeletionException::storageFailed();
                }

                $sharedReferenceExists = ContractDocument::query()
                    ->where('file_path', $originalPath)
                    ->whereKeyNot($locked->getKey())
                    ->lockForUpdate()
                    ->exists();

                if ($sharedReferenceExists) {
                    Log::warning('Contract document path is shared by legacy metadata.', $this->logContext(
                        $documentId,
                        $contractId,
                        $originalPath,
                        null,
                        'shared_legacy_path'
                    ));
                    $locked->delete();
                    $this->recordDeleted($contract, $locked, $metadata, $actor);

                    return;
                }

                if (! $disk->exists($originalPath)) {
                    Log::warning('Contract document physical file is missing.', $this->logContext(
                        $documentId,
                        $contractId,
                        $originalPath,
                        null,
                        'physical_file_missing'
                    ));
                    $locked->delete();
                    $this->recordDeleted($contract, $locked, $metadata, $actor);

                    return;
                }

                $quarantinePath = ContractDocumentPath::QUARANTINE_PREFIX.'/'.Str::uuid();

                if (! $disk->move($originalPath, $quarantinePath)) {
                    throw ContractDocumentDeletionException::storageFailed();
                }

                $fileWasMoved = true;

                if ($disk->exists($originalPath) || ! $disk->exists($quarantinePath)) {
                    throw ContractDocumentDeletionException::storageFailed();
                }

                $locked->delete();
                $this->recordDeleted($contract, $locked, $metadata, $actor);
            });
        } catch (ContractDocumentDeletionException $exception) {
            $this->restoreAfterFailure(
                $disk,
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath,
                $exception
            );

            throw $exception;
        } catch (QueryException $exception) {
            $this->restoreAfterFailure(
                $disk,
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath,
                $exception
            );

            throw ContractDocumentDeletionException::databaseFailed($exception);
        } catch (FilesystemException $exception) {
            $this->restoreAfterFailure(
                $disk,
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath,
                $exception
            );

            throw ContractDocumentDeletionException::storageFailed($exception);
        } catch (Throwable $exception) {
            $this->restoreAfterFailure(
                $disk,
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath,
                $exception
            );

            throw $exception;
        }

        if ($fileWasMoved && $contractId !== null && $originalPath !== null && $quarantinePath !== null) {
            $this->purgeQuarantineAfterCommit(
                $disk,
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordDeleted(
        Contract $contract,
        ContractDocument $document,
        array $metadata,
        ?User $actor
    ): void {
        $this->activityRecorder->record(
            CompanyActivitySnapshot::companyFor($contract),
            CompanyActivityEventType::DocumentDeleted,
            CompanyActivityCategory::Documents,
            CompanyActivityVisibilityScope::Documents,
            subject: $document,
            metadata: $metadata,
            actor: $actor,
        );
    }

    private function restoreAfterFailure(
        mixed $disk,
        int|string $documentId,
        int|string|null $contractId,
        ?string $originalPath,
        ?string $quarantinePath,
        Throwable $operationException
    ): void {
        if ($contractId === null || $originalPath === null || $quarantinePath === null) {
            return;
        }

        try {
            $quarantineExists = $disk->exists($quarantinePath);
            $originalExists = $disk->exists($originalPath);

            if (! $quarantineExists && $originalExists) {
                return;
            }

            $restored = $quarantineExists
                && ! $originalExists
                && $disk->move($quarantinePath, $originalPath)
                && $disk->exists($originalPath)
                && ! $disk->exists($quarantinePath);

            if ($restored) {
                return;
            }
        } catch (Throwable $restoreException) {
            Log::critical('Contract document quarantine restore failed.', [
                ...$this->logContext(
                    $documentId,
                    $contractId,
                    $originalPath,
                    $quarantinePath,
                    'rollback_restore_failed'
                ),
                'database_exception' => $operationException instanceof QueryException
                    ? $operationException
                    : null,
                'restore_exception' => $restoreException,
            ]);

            return;
        }

        Log::critical('Contract document quarantine restore failed.', [
            ...$this->logContext(
                $documentId,
                $contractId,
                $originalPath,
                $quarantinePath,
                'rollback_restore_failed'
            ),
            'database_exception' => $operationException instanceof QueryException
                ? $operationException
                : null,
            'restore_exception' => null,
        ]);
    }

    private function purgeQuarantineAfterCommit(
        mixed $disk,
        int|string $documentId,
        int|string $contractId,
        string $originalPath,
        string $quarantinePath
    ): void {
        try {
            if (! $disk->delete($quarantinePath)) {
                Log::critical('Contract document quarantine cleanup failed.', [
                    ...$this->logContext(
                        $documentId,
                        $contractId,
                        $originalPath,
                        $quarantinePath,
                        'post_commit_delete_returned_false'
                    ),
                    'exception' => null,
                ]);

                return;
            }

            if (! $disk->exists($quarantinePath)) {
                return;
            }

            Log::critical('Contract document quarantine cleanup failed.', [
                ...$this->logContext(
                    $documentId,
                    $contractId,
                    $originalPath,
                    $quarantinePath,
                    'post_commit_quarantine_file_still_exists'
                ),
                'exception' => null,
            ]);
        } catch (Throwable $cleanupException) {
            Log::critical('Contract document quarantine cleanup failed.', [
                ...$this->logContext(
                    $documentId,
                    $contractId,
                    $originalPath,
                    $quarantinePath,
                    'post_commit_quarantine_delete_failed'
                ),
                'exception' => $cleanupException,
            ]);
        }
    }

    /** @return array<string, int|string|null> */
    private function logContext(
        int|string $documentId,
        int|string $contractId,
        ?string $originalPath,
        ?string $quarantinePath,
        string $reason
    ): array {
        return [
            'document_id' => $documentId,
            'contract_id' => $contractId,
            'disk' => ContractDocumentPath::DISK,
            'original_relative_path' => $originalPath,
            'quarantine_relative_path' => $quarantinePath,
            'reason' => $reason,
        ];
    }
}
