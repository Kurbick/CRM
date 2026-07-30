<?php

namespace Tests\Feature;

use App\Models\ContractDocument;
use App\Support\Access\PermissionName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Authorization\AuthorizationTestCase;
use ZipArchive;

class ContractDocumentMimeValidationTest extends AuthorizationTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_real_pdf_detected_by_fileinfo_is_accepted(): void
    {
        $path = $this->temporaryPath('pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        $file = new UploadedFile($path, 'real-document.pdf', null, null, true);
        $this->assertSame('application/pdf', $file->getMimeType());

        $document = $this->upload($file);

        $this->assertStringEndsWith('.pdf', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_real_docx_package_detected_by_fileinfo_is_accepted(): void
    {
        $path = $this->validDocxPath();
        $file = new UploadedFile($path, 'real-document.docx', null, null, true);
        $this->assertContains($file->getMimeType(), [
            'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $document = $this->upload($file);

        $this->assertStringEndsWith('.docx', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_arbitrary_zip_renamed_to_docx_is_rejected(): void
    {
        $path = $this->temporaryPath('docx');
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('payload.txt', 'This is not an Office document.');
        $archive->close();
        $file = new UploadedFile($path, 'fake-office.docx', null, null, true);
        $this->assertSame('application/zip', $file->getMimeType());

        $contract = $this->contract($this->company('Invalid DOCX company'));
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);
        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => $file,
        ])->assertSessionHasErrors([
            'document' => 'Расширение файла не соответствует его содержимому.',
        ]);

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    #[DataProvider('dangerousExtensionProvider')]
    public function test_dangerous_extensions_are_rejected_without_side_effects(
        string $extension,
        string $mimeType,
    ): void {
        $contract = $this->contract($this->company('Dangerous '.$extension));
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'other',
            'document' => UploadedFile::fake()->create("payload.{$extension}", 4, $mimeType),
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public static function dangerousExtensionProvider(): array
    {
        return [
            'php' => ['php', 'application/x-php'],
            'phtml' => ['phtml', 'application/x-php'],
            'phar' => ['phar', 'application/octet-stream'],
            'svg' => ['svg', 'image/svg+xml'],
            'html' => ['html', 'text/html'],
            'javascript' => ['js', 'text/javascript'],
            'executable' => ['exe', 'application/x-msdownload'],
            'shell' => ['sh', 'application/x-sh'],
        ];
    }

    private function upload(UploadedFile $file): ContractDocument
    {
        $contract = $this->contract($this->company('Real MIME company'));
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => $file,
        ])->assertRedirect(route('home'));

        return ContractDocument::query()->sole();
    }

    private function validDocxPath(): string
    {
        $path = $this->temporaryPath('docx');
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/word/document.xml" '
            .'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>'
        );
        $archive->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>'
        );
        $archive->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body><w:p><w:r><w:t>Contract document fixture</w:t></w:r></w:p></w:body>'
            .'</w:document>'
        );
        $archive->close();

        return $path;
    }

    private function temporaryPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contract-document-fixture-');
        $this->assertNotFalse($path);
        $target = $path.'.'.$extension;
        $this->assertTrue(rename($path, $target));
        $this->temporaryFiles[] = $target;

        return $target;
    }
}
