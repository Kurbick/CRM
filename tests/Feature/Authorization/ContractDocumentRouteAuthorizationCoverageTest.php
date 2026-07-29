<?php

namespace Tests\Feature\Authorization;

use App\Http\Controllers\Web\ContractDocumentController;
use App\Models\ContractDocument;
use App\Support\Access\PermissionName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class ContractDocumentRouteAuthorizationCoverageTest extends AuthorizationTestCase
{
    private const MATRIX = [
        [
            'route' => 'contracts.documents.store',
            'methods' => ['POST'],
            'controller' => ContractDocumentController::class,
            'ability' => 'create',
            'target' => 'document_create',
            'permission' => PermissionName::ContractDocumentsUpload->value,
            'wrong_permission' => PermissionName::ContractDocumentsDownload->value,
            'scenario' => 'store',
            'db_invariant' => 'no_document_row',
            'storage_invariant' => 'disk_empty',
        ],
        [
            'route' => 'contract-documents.download',
            'methods' => ['GET'],
            'controller' => ContractDocumentController::class,
            'ability' => 'download',
            'target' => 'document_model',
            'permission' => PermissionName::ContractDocumentsDownload->value,
            'wrong_permission' => PermissionName::ContractDocumentsDelete->value,
            'scenario' => 'download',
            'db_invariant' => 'document_row_unchanged',
            'storage_invariant' => 'original_file_unchanged',
        ],
        [
            'route' => 'contract-documents.destroy',
            'methods' => ['DELETE'],
            'controller' => ContractDocumentController::class,
            'ability' => 'delete',
            'target' => 'document_model',
            'permission' => PermissionName::ContractDocumentsDelete->value,
            'wrong_permission' => PermissionName::ContractDocumentsUpload->value,
            'scenario' => 'destroy',
            'db_invariant' => 'document_row_exists',
            'storage_invariant' => 'original_file_exists',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_every_document_controller_route_is_in_matrix_with_exact_controller_and_methods(): void
    {
        $sensitiveMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getControllerClass() === ContractDocumentController::class
                && array_intersect($sensitiveMethods, $route->methods()))
            ->keyBy(fn ($route) => $route->getName());

        $this->assertSame(
            collect(self::MATRIX)->pluck('route')->sort()->values()->all(),
            $routes->keys()->sort()->values()->all()
        );

        foreach (self::MATRIX as $definition) {
            $route = $routes[$definition['route']];
            $this->assertSame($definition['controller'], $route->getControllerClass());
            $this->assertSame(
                $definition['methods'],
                array_values(array_intersect($sensitiveMethods, $route->methods()))
            );
        }
    }

    #[DataProvider('provider')]
    public function test_matrix_metadata_drives_direct_gate_checks(array $definition): void
    {
        $target = $this->resolveTarget($definition['target']);
        $withoutPermission = $this->actingAsPermissions();
        $wrongPermission = $this->actingAsPermissions([$definition['wrong_permission']]);
        $exactPermission = $this->actingAsPermissions([$definition['permission']]);

        $this->assertFalse(Gate::forUser($withoutPermission)->allows($definition['ability'], $target));
        $this->assertFalse(Gate::forUser($wrongPermission)->allows($definition['ability'], $target));
        $this->assertTrue(Gate::forUser($exactPermission)->allows($definition['ability'], $target));
        $this->assertFalse($exactPermission->hasRole('administrator'));
        $this->assertCount(1, $exactPermission->permissions);
    }

    #[DataProvider('provider')]
    public function test_http_without_permission_is_forbidden_and_preserves_db_and_storage(array $definition): void
    {
        $this->actingAsPermissions();
        $this->assertForbiddenHttp($definition);
    }

    #[DataProvider('provider')]
    public function test_http_with_wrong_permission_is_forbidden_and_preserves_db_and_storage(array $definition): void
    {
        $this->actingAsPermissions([$definition['wrong_permission']]);
        $this->assertForbiddenHttp($definition);
    }

    #[DataProvider('provider')]
    public function test_http_with_exact_permission_performs_real_storage_and_db_mutation(array $definition): void
    {
        $user = $this->actingAsPermissions([$definition['permission']]);
        $this->assertFalse($user->hasRole('administrator'));
        $this->assertCount(1, $user->permissions);
        $this->assertAllowedHttp($definition);
    }

    public static function provider(): array
    {
        return collect(self::MATRIX)
            ->mapWithKeys(fn (array $definition): array => [$definition['route'] => [$definition]])
            ->all();
    }

    private function resolveTarget(string $target): mixed
    {
        $contract = $this->contract($this->company('Document matrix target '.uniqid()));

        return match ($target) {
            'document_create' => [ContractDocument::class, $contract],
            'document_model' => $this->document($contract),
            default => $this->fail("Unknown ContractDocument Gate target [{$target}]."),
        };
    }

    private function assertForbiddenHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Document matrix forbidden '.uniqid()));

        if ($definition['scenario'] === 'store') {
            $this->assertSame('no_document_row', $definition['db_invariant']);
            $this->assertSame('disk_empty', $definition['storage_invariant']);
            $this->post(route($definition['route'], $contract), [
                'document_type' => 'signed',
                'document' => UploadedFile::fake()->create('forbidden.pdf', 4, 'application/pdf'),
            ])->assertForbidden();
            $this->assertDatabaseCount('contract_documents', 0);
            Storage::disk('local')->assertDirectoryEmpty('/');

            return;
        }

        if ($definition['scenario'] === 'download') {
            $this->assertSame('document_row_unchanged', $definition['db_invariant']);
            $this->assertSame('original_file_unchanged', $definition['storage_invariant']);
            $document = $this->document($contract);
            $original = $document->fresh()->getAttributes();
            $disk = Storage::disk('local');
            Storage::shouldReceive('disk')->never();

            $this->get(route($definition['route'], $document))->assertForbidden();
            $this->assertSame($original, $document->fresh()->getAttributes());
            $this->assertTrue($disk->exists($document->file_path));
            $this->assertSame('MATRIX-CONTENT', $disk->get($document->file_path));

            return;
        }

        $this->assertSame('document_row_exists', $definition['db_invariant']);
        $this->assertSame('original_file_exists', $definition['storage_invariant']);
        $document = $this->document($contract);
        $this->delete(route($definition['route'], $document))->assertForbidden();
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('contract-documents/.quarantine'));
    }

    private function assertAllowedHttp(array $definition): void
    {
        $contract = $this->contract($this->company('Document matrix allowed '.uniqid()));

        if ($definition['scenario'] === 'store') {
            $this->post(route($definition['route'], $contract), [
                'document_type' => 'signed',
                'document' => UploadedFile::fake()->create('allowed.pdf', 4, 'application/pdf'),
            ])->assertRedirect(route('home'));
            $document = ContractDocument::query()->where('contract_id', $contract->id)->sole();
            Storage::disk('local')->assertExists($document->file_path);

            return;
        }

        if ($definition['scenario'] === 'download') {
            $document = $this->document($contract);
            $original = $document->fresh()->getAttributes();
            $response = $this->get(route($definition['route'], $document))->assertOk();
            $this->assertSame('MATRIX-CONTENT', $response->streamedContent());
            $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame($original, $document->fresh()->getAttributes());
            Storage::disk('local')->assertExists($document->file_path);

            return;
        }

        $document = $this->document($contract);
        $this->delete(route($definition['route'], $document))->assertRedirect(route('home'));
        $this->assertDatabaseMissing('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
    }

    private function document($contract): ContractDocument
    {
        $path = "contract-documents/{$contract->id}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, 'MATRIX-CONTENT');

        return $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'matrix.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 14,
        ]);
    }
}
