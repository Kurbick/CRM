<?php

namespace Tests\Feature;

use App\Models\ContractDocument;
use App\Support\Access\PermissionName;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContractDocumentDownloadTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_local_private_disk_cannot_serve_temporary_urls(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    public function test_forbidden_download_returns_403_before_missing_file_is_disclosed(): void
    {
        $existing = $this->document('existing.pdf', 'EXISTING-CONTENT', true);
        $missing = $this->document('missing.pdf', '', false);
        $disk = Storage::disk('local');
        $this->actingAsPermissions();
        Storage::shouldReceive('disk')->never();

        $this->get(route('contract-documents.download', $existing))->assertForbidden();
        $this->get(route('contract-documents.download', $missing))->assertForbidden();
        $this->assertTrue($disk->exists($existing->file_path));
        $this->assertSame('EXISTING-CONTENT', $disk->get($existing->file_path));
    }

    public function test_wrong_permissions_do_not_allow_download(): void
    {
        $document = $this->document('wrong.pdf', 'WRONG-CONTENT', true);

        foreach ([PermissionName::ContractDocumentsUpload, PermissionName::ContractDocumentsDelete] as $wrong) {
            $this->actingAsPermissions([$wrong->value]);
            $this->get(route('contract-documents.download', $document))->assertForbidden();
        }
    }

    public function test_download_permission_without_contract_view_gets_safe_attachment_and_does_not_change_metadata(): void
    {
        $document = $this->document("report\r\nX-Injected: yes.pdf", 'PRIVATE-DOCUMENT-CONTENT', true);
        $before = $document->fresh()->getAttributes();
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);

        $this->get(route('contracts.show', $document->contract))->assertForbidden();

        $response = $this->get(route('contract-documents.download', $document))->assertOk();

        $this->assertSame('PRIVATE-DOCUMENT-CONTENT', $response->streamedContent());
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\r", (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\n", (string) $response->headers->get('Content-Disposition'));
        $this->assertFalse($response->headers->has('X-Injected'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(
            $document->file_path,
            (string) json_encode($response->headers->allPreserveCaseWithoutCookies())
        );
        $this->assertSame($before, $document->fresh()->getAttributes());
    }

    public function test_authorized_missing_or_unsafe_path_returns_controlled_404_without_path_disclosure(): void
    {
        $missing = $this->document('missing.pdf', '', false);
        $unsafe = $missing->contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'unsafe.pdf',
            'file_path' => '../ABSOLUTE-SECRET-PATH.pdf',
        ]);
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);

        foreach ([$missing, $unsafe] as $document) {
            $this->get(route('contract-documents.download', $document))
                ->assertNotFound()
                ->assertDontSee('ABSOLUTE-SECRET-PATH')
                ->assertDontSee(storage_path());
        }
    }

    public function test_all_unsafe_legacy_paths_return_404_without_any_filesystem_lookup(): void
    {
        $contract = $this->contract($this->company('Unsafe path company'));
        $otherContract = $this->contract($this->company('Other unsafe path company'));
        $paths = [
            "contract-documents/{$contract->id}/bad\0.pdf",
            "contract-documents/{$contract->id}/bad\nname.pdf",
            "contract-documents/{$contract->id}/bad\rname.pdf",
            "contract-documents/{$contract->id}/\u{0001}bad.pdf",
            "contract-documents/{$contract->id}/bad\u{200E}format.pdf",
            '../secret.pdf',
            '/absolute/file.pdf',
            'C:\\secret.pdf',
            "contract-documents-evil/{$contract->id}/file.pdf",
            "contract-documents/{$otherContract->id}/file.pdf",
            "contracts/{$otherContract->id}/documents/file.pdf",
            '',
            "contract-documents/{$contract->id}//empty-segment.pdf",
        ];
        $documents = collect($paths)->map(fn (string $path): ContractDocument => $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'unsafe.pdf',
            'file_path' => $path,
        ]));
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);
        Storage::shouldReceive('disk')->never();

        foreach ($documents as $index => $document) {
            $response = $this->get(route('contract-documents.download', $document))
                ->assertNotFound()
                ->assertDontSee('CorruptedPathDetected')
                ->assertDontSee('FilesystemException');
            if ($paths[$index] !== '') {
                $response->assertDontSee($paths[$index]);
            }
        }
    }

    public function test_safe_legacy_contract_directory_remains_downloadable(): void
    {
        $contract = $this->contract($this->company('Legacy path company'));
        $path = "contracts/{$contract->id}/documents/legacy.pdf";
        Storage::disk('local')->put($path, 'LEGACY-CONTENT');
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'legacy.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 14,
        ]);
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);

        $response = $this->get(route('contract-documents.download', $document))->assertOk();

        $this->assertSame('LEGACY-CONTENT', $response->streamedContent());
        Storage::disk('local')->assertExists($path);
    }

    public function test_download_opens_stream_before_response_and_does_not_repeat_storage_lookup(): void
    {
        $document = $this->document('eager-stream.pdf', '', false);
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'EAGER-STREAM-CONTENT');
        rewind($stream);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('readStream')
            ->once()
            ->with($document->file_path)
            ->andReturn($stream);
        $disk->shouldNotReceive('exists', 'get', 'download');
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);

        $response = $this->get(route('contract-documents.download', $document))->assertOk();

        $this->assertIsResource($stream);
        $this->assertSame('EAGER-STREAM-CONTENT', $response->streamedContent());
        $this->assertFalse(is_resource($stream));
    }

    #[DataProvider('safeFilenameProvider')]
    public function test_content_disposition_safely_handles_display_filename(string $filename, bool $expectUnicode): void
    {
        $document = $this->document($filename, 'HEADER-CONTENT', true);
        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);

        $response = $this->get(route('contract-documents.download', $document))->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment;', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertFalse($response->headers->has('X-Evil'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        if ($expectUnicode) {
            $this->assertStringContainsString('filename*=', $disposition);
        }

        if (str_contains($filename, '"')) {
            $this->assertStringContainsString('\\"', $disposition);
        }
    }

    public static function safeFilenameProvider(): array
    {
        return [
            'ASCII' => ['report.pdf', false],
            'Unicode' => ['Müqavilə-отчёт.pdf', true],
            'quotes' => ['quarterly "report".pdf', false],
            'semicolon' => ['report;final.pdf', false],
            'CRLF legacy metadata' => ["report.pdf\r\nX-Evil: yes", false],
            'near maximum length' => [str_repeat('a', 170).'.pdf', false],
        ];
    }

    private function document(string $name, string $contents, bool $write): ContractDocument
    {
        $contract = $this->contract($this->company('Download company '.uniqid()));
        $path = "contract-documents/{$contract->id}/".uniqid().'.pdf';
        if ($write) {
            Storage::disk('local')->put($path, $contents);
        }

        return $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => $name,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
        ]);
    }
}
