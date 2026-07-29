<?php

namespace Tests\Feature\Authorization;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionName;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;

class ContractDocumentAuthorizationTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_upload_requires_exact_permission_before_validation_or_storage(): void
    {
        $contract = $this->contract($this->company());
        $payload = ['document_type' => 'signed', 'document' => $this->pdf('FORBIDDEN-MARKER.pdf')];

        $this->actingAsPermissions();
        $this->post(route('contracts.documents.store', $contract), $payload)->assertForbidden();
        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');

        foreach ([PermissionName::ContractDocumentsDownload, PermissionName::ContractDocumentsDelete] as $wrong) {
            $this->actingAsPermissions([$wrong->value]);
            $this->post(route('contracts.documents.store', $contract), [
                'document_type' => 'signed',
                'document' => $this->pdf('FORBIDDEN-'.$wrong->name.'.pdf'),
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_exact_upload_uses_route_parent_and_ignores_protected_request_fields(): void
    {
        $contract = $this->contract($this->company('Document parent A'));
        $other = $this->contract($this->company('Document parent B'));
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $response = $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => $this->pdf('agreement.pdf'),
            'comment' => 'Visible comment',
            'contract_id' => $other->id,
            'company_id' => $other->company_id,
            'file_path' => 'malicious/path.php',
            'path' => 'malicious/path.php',
            'disk' => 'public',
            'stored_filename' => 'shell.php',
            'original_name' => 'malicious-original.php',
            'uploaded_by' => 999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'unknown_marker' => 'MALICIOUS-DOCUMENT-FIELD',
        ]);

        $response->assertRedirect(route('dashboard'))->assertSessionHas('success');
        $document = ContractDocument::query()->sole();
        $this->assertSame($contract->id, $document->contract_id);
        $this->assertSame('agreement.pdf', $document->original_name);
        $this->assertMatchesRegularExpression(
            '#^contract-documents/'.$contract->id.'/[0-9a-f-]{36}\.pdf$#',
            $document->file_path
        );
        $this->assertStringNotContainsString('agreement', $document->file_path);
        $this->assertStringNotContainsString('malicious', $document->file_path);
        $this->assertNotSame('2000-01-01 00:00:00', $document->created_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame('2000-01-01 00:00:00', $document->updated_at?->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('unknown_marker', $document->getAttributes());
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame(100 * 1024, $document->file_size);
    }

    public function test_administrator_bypasses_permission_but_not_file_validation(): void
    {
        $contract = $this->contract($this->company());
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator);

        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_invalid_and_dangerous_uploads_leave_database_and_storage_empty(): void
    {
        $contract = $this->contract($this->company());
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);
        $files = [
            UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'),
            UploadedFile::fake()->create('oversized.pdf', 10 * 1024 + 1, 'application/pdf'),
            UploadedFile::fake()->create('invoice.pdf.php', 4, 'application/x-php'),
            UploadedFile::fake()->create('mismatch.pdf', 4, 'image/png'),
            UploadedFile::fake()->create('without-extension', 4, 'application/pdf'),
            UploadedFile::fake()->create('../traversal.pdf', 4, 'application/pdf'),
            UploadedFile::fake()->create("header\r\nInjected.pdf", 4, 'application/pdf'),
            UploadedFile::fake()->create(str_repeat('Документ', 30).'.pdf', 4, 'application/pdf'),
        ];

        foreach ($files as $file) {
            $this->post(route('contracts.documents.store', $contract), [
                'document_type' => 'signed',
                'document' => $file,
            ])->assertSessionHasErrors('document');
        }

        $this->assertDatabaseCount('contract_documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_empty_upload_has_a_specific_safe_validation_message(): void
    {
        $contract = $this->contract($this->company());
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'),
        ])->assertSessionHasErrors([
            'document' => 'Файл не должен быть пустым.',
        ]);
    }

    public function test_extension_content_mismatch_has_a_specific_safe_validation_message(): void
    {
        $contract = $this->contract($this->company());
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => UploadedFile::fake()->create('mismatch.pdf', 4, 'image/png'),
        ])->assertSessionHasErrors([
            'document' => 'Расширение файла не соответствует его содержимому.',
        ]);
    }

    public function test_download_and_delete_require_their_exact_independent_permissions(): void
    {
        $contract = $this->contract($this->company());
        $downloadDocument = $this->document($contract, 'download.pdf', 'DOWNLOAD-CONTENT');
        $deleteDocument = $this->document($contract, 'delete.pdf', 'DELETE-CONTENT');

        $this->actingAsPermissions([PermissionName::ContractDocumentsDownload->value]);
        $this->get(route('contract-documents.download', $downloadDocument))->assertOk();
        $this->delete(route('contract-documents.destroy', $deleteDocument))->assertForbidden();
        $this->assertDatabaseHas('contract_documents', ['id' => $deleteDocument->id]);
        Storage::disk('local')->assertExists($deleteDocument->file_path);

        $this->actingAsPermissions([PermissionName::ContractDocumentsDelete->value]);
        $this->get(route('contract-documents.download', $downloadDocument))->assertForbidden();
        $this->delete(route('contract-documents.destroy', $deleteDocument))
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('contract_documents', ['id' => $deleteDocument->id]);
        Storage::disk('local')->assertMissing($deleteDocument->file_path);
    }

    public function test_administrator_delete_uses_the_same_storage_lifecycle(): void
    {
        $contract = $this->contract($this->company());
        $document = $this->document($contract, 'administrator.pdf', 'ADMINISTRATOR-CONTENT');
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator);

        $this->delete(route('contract-documents.destroy', $document))
            ->assertRedirect(route('contracts.show', $contract));

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('contract-documents/.quarantine'));
    }

    public function test_mutation_redirects_follow_contract_company_dashboard_fallbacks(): void
    {
        foreach ([
            'contract' => PermissionName::ContractsView,
            'company' => PermissionName::CompaniesView,
            'dashboard' => null,
        ] as $destination => $viewPermission) {
            $contract = $this->contract($this->company('Document redirect '.$destination));
            $expected = match ($destination) {
                'contract' => route('contracts.show', $contract),
                'company' => route('companies.show', $contract->company),
                default => route('dashboard'),
            };
            $permissions = [PermissionName::ContractDocumentsUpload->value];
            if ($viewPermission !== null) {
                $permissions[] = $viewPermission->value;
            }
            $this->actingAsPermissions($permissions);
            $this->post(route('contracts.documents.store', $contract), [
                'document_type' => 'other',
                'document' => $this->pdf('redirect-'.$destination.'.pdf'),
            ])->assertRedirect($expected);

            $document = ContractDocument::query()->where('contract_id', $contract->id)->latest('id')->firstOrFail();
            $deletePermissions = [PermissionName::ContractDocumentsDelete->value];
            if ($viewPermission !== null) {
                $deletePermissions[] = $viewPermission->value;
            }
            $this->actingAsPermissions($deletePermissions);
            $this->delete(route('contract-documents.destroy', $document))->assertRedirect($expected);
        }
    }

    public function test_contract_show_metadata_and_actions_follow_permissions_without_technical_disclosure(): void
    {
        $contract = $this->contract($company = $this->company('DOCUMENT-COMPANY-NAME'));
        $company->forceFill([
            'voen' => 'DOCUMENT-SECRET-VOEN',
            'iban' => 'AZ00-DOCUMENT-SECRET-IBAN',
        ])->save();
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'VISIBLE-DISPLAY-NAME.pdf',
            'file_path' => "contract-documents/{$contract->id}/TECHNICAL-GENERATED-SECRET.pdf",
            'mime_type' => 'TECHNICAL-MIME-SECRET',
            'file_size' => 1024,
            'comment' => 'VISIBLE-DOCUMENT-COMMENT',
        ]);

        $this->actingAsPermissions([PermissionName::ContractsView->value]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $withoutActions = $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee('VISIBLE-DISPLAY-NAME.pdf')
            ->assertSee('VISIBLE-DOCUMENT-COMMENT')
            ->assertDontSee('TECHNICAL-GENERATED-SECRET')
            ->assertDontSee('TECHNICAL-MIME-SECRET')
            ->assertDontSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertDontSee(route('contract-documents.download', $document), false)
            ->assertDontSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertDontSee('name="_method" value="DELETE"', false);
        $withoutActions->assertDontSee('/storage/', false);
        $documentQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'contract_documents')
        );
        $this->assertCount(1, $documentQueries);
        DB::disableQueryLog();

        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractDocumentsUpload->value,
            PermissionName::ContractDocumentsDownload->value,
            PermissionName::ContractDocumentsDelete->value,
        ]);
        $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee(route('contract-documents.download', $document), false)
            ->assertSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('TECHNICAL-GENERATED-SECRET');
    }

    public function test_contract_show_with_upload_permission_displays_only_upload_action(): void
    {
        [$contract, $document] = $this->uiDocumentContext('UPLOAD-ONLY');
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractDocumentsUpload->value,
        ]);

        $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee($document->original_name)
            ->assertSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertDontSee(route('contract-documents.download', $document), false)
            ->assertDontSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertDontSee('name="_method" value="DELETE"', false);
    }

    public function test_contract_show_with_download_permission_displays_only_download_action(): void
    {
        [$contract, $document] = $this->uiDocumentContext('DOWNLOAD-ONLY');
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractDocumentsDownload->value,
        ]);

        $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee($document->original_name)
            ->assertSee(route('contract-documents.download', $document), false)
            ->assertDontSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertDontSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertDontSee('name="_method" value="DELETE"', false);
    }

    public function test_contract_show_with_delete_permission_displays_only_delete_action(): void
    {
        [$contract, $document] = $this->uiDocumentContext('DELETE-ONLY');
        $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractDocumentsDelete->value,
        ]);

        $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee($document->original_name)
            ->assertSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertDontSee(route('contract-documents.download', $document), false);
    }

    public function test_administrator_sees_all_contract_document_actions(): void
    {
        [$contract, $document] = $this->uiDocumentContext('ADMINISTRATOR-UI');
        $administrator = User::factory()->create();
        $administrator->assignRole(Role::findByName('administrator'));
        $this->actingAs($administrator);

        $this->get(route('contracts.show', $contract))->assertOk()
            ->assertSee($document->original_name)
            ->assertSee('action="'.route('contracts.documents.store', $contract).'"', false)
            ->assertSee(route('contract-documents.download', $document), false)
            ->assertSee('action="'.route('contract-documents.destroy', $document).'"', false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    public function test_unsafe_delete_paths_return_safe_error_without_filesystem_operations(): void
    {
        $contract = $this->contract($this->company('Unsafe delete company'));
        $otherContract = $this->contract($this->company('Other unsafe delete company'));
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
        ];
        $documents = collect($paths)->map(fn (string $path): ContractDocument => $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => 'unsafe.pdf',
            'file_path' => $path,
        ]));
        $realDisk = Storage::disk('local');
        $realDisk->put('unrelated-object.pdf', 'UNRELATED-CONTENT');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldNotReceive('exists', 'move', 'delete');
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->times(count($paths))->andReturn($disk);
        $this->app->instance(FilesystemManager::class, $manager);
        $this->actingAsPermissions([PermissionName::ContractDocumentsDelete->value]);

        foreach ($documents as $index => $document) {
            $response = $this->delete(route('contract-documents.destroy', $document))
                ->assertRedirect(route('dashboard'))
                ->assertSessionHas('error', 'Не удалось удалить документ.');
            if ($paths[$index] !== '') {
                $response->assertDontSee($paths[$index]);
            }
            $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        }

        $this->assertSame('UNRELATED-CONTENT', $realDisk->get('unrelated-object.pdf'));
        $this->assertSame([], $realDisk->allFiles('contract-documents/.quarantine'));
    }

    public function test_http_delete_succeeds_when_post_commit_quarantine_cleanup_returns_false(): void
    {
        $contract = $this->contract($this->company('HTTP cleanup company'));
        $document = $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'cleanup.pdf',
            'file_path' => "contract-documents/{$contract->id}/cleanup.pdf",
        ]);
        $files = [$document->file_path => true];
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturnUsing(
            function (string $path) use (&$files): bool {
                return $files[$path] ?? false;
            }
        );
        $disk->shouldReceive('move')->once()->andReturnUsing(
            function (string $from, string $to) use (&$files): bool {
                $files[$from] = false;
                $files[$to] = true;

                return true;
            }
        );
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $manager = Mockery::mock(FilesystemManager::class);
        $manager->shouldReceive('disk')->with('local')->once()->andReturn($disk);
        $this->app->instance(FilesystemManager::class, $manager);
        Log::shouldReceive('critical')->once()->with(
            'Contract document quarantine cleanup failed.',
            Mockery::on(fn (array $context): bool => $context['document_id'] === $document->id
                && $context['contract_id'] === $contract->id
                && $context['disk'] === 'local'
                && $context['original_relative_path'] === $document->file_path
                && str_starts_with($context['quarantine_relative_path'], 'contract-documents/.quarantine/')
                && $context['reason'] === 'post_commit_delete_returned_false'
                && $context['exception'] === null)
        );
        $this->actingAsPermissions([PermissionName::ContractDocumentsDelete->value]);

        $this->delete(route('contract-documents.destroy', $document))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Документ удалён.');

        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        $this->assertFalse($files[$document->file_path]);
        $this->assertCount(1, array_filter($files));
    }

    public function test_operation_only_responses_do_not_disclose_contract_or_company_pages(): void
    {
        $contract = $this->contract($this->company('DOCUMENT-DISCLOSURE-COMPANY'));
        $this->actingAsPermissions([PermissionName::ContractDocumentsUpload->value]);

        $response = $this->post(route('contracts.documents.store', $contract), [
            'document_type' => 'signed',
            'document' => $this->pdf('minimal.pdf'),
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertStringNotContainsString(route('contracts.show', $contract), (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString(route('companies.show', $contract->company), (string) $response->headers->get('Location'));
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    private function document(Contract $contract, string $name, string $contents): ContractDocument
    {
        $path = "contract-documents/{$contract->id}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, $contents);

        return $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => $name,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($contents),
        ]);
    }

    /** @return array{Contract, ContractDocument} */
    private function uiDocumentContext(string $marker): array
    {
        $contract = $this->contract($this->company($marker.' company'));
        $document = $contract->documents()->create([
            'document_type' => 'other',
            'original_name' => $marker.'.pdf',
            'file_path' => "contract-documents/{$contract->id}/".strtolower($marker).'.pdf',
            'file_size' => 1024,
        ]);

        return [$contract, $document];
    }
}
