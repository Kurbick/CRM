<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Authorization\AuthorizationTestCase;
use Tests\Support\DomainQueryRecorder;

class ApiContractDocumentIntegrityTest extends AuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_api_contract_delete_denies_existing_and_missing_ids_before_domain_queries(): void
    {
        [$contract, $document] = $this->contractWithDocument();
        $disk = Storage::disk('local');
        $this->actingAsPermissions();
        Storage::shouldReceive('disk')->never();

        foreach ([$contract->id, $contract->id + 1_000_000] as $id) {
            $capture = (new DomainQueryRecorder)->capture(
                fn () => $this->deleteJson(route('api.contracts.destroy', $id))
            );

            $capture['result']->assertForbidden();
            $this->assertSame([], $capture['records']);
        }

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        $this->assertTrue($disk->exists($document->file_path));
    }

    public function test_api_contract_delete_preserves_document_metadata_and_file_when_dependency_blocks(): void
    {
        [$contract, $document] = $this->contractWithDocument();
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->deleteJson(route('api.contracts.destroy', $contract))
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.'
            );

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('contract_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_api_contract_delete_uses_shared_action_for_empty_contract(): void
    {
        $contract = $this->contract($this->company('API deletable contract'));
        $this->actingAsPermissions([PermissionName::ContractsDelete->value]);

        $this->deleteJson(route('api.contracts.destroy', $contract))
            ->assertOk()
            ->assertJson(['message' => 'Контракт удалён']);

        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
    }

    public function test_api_contract_store_rejects_removed_signed_document_field(): void
    {
        $company = $this->company('API stale store field');
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->postJson(route('api.companies.contracts.store', $company), [
            'contract_number' => 'API-STALE-STORE',
            'start_date' => '2026-08-01',
            'status' => 'active',
            'signed_document' => 'legacy/public/path.pdf',
        ])->assertUnprocessable()->assertJsonValidationErrors('signed_document');

        $this->assertDatabaseMissing('contracts', ['contract_number' => 'API-STALE-STORE']);
    }

    public function test_api_contract_update_rejects_removed_signed_document_without_partial_mutation(): void
    {
        $contract = $this->contract($this->company('API stale update field'));
        $before = (array) DB::table('contracts')->where('id', $contract->id)->first();
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $this->patchJson(route('api.contracts.update', $contract), [
            'comment' => 'Must not be saved',
            'signed_document' => 'legacy/public/path.pdf',
        ])->assertUnprocessable()->assertJsonValidationErrors('signed_document');

        $this->assertSame(
            $before,
            (array) DB::table('contracts')->where('id', $contract->id)->first(),
        );
    }

    /** @return array{Contract, ContractDocument} */
    private function contractWithDocument(): array
    {
        $contract = $this->contract($this->company('API document contract '.uniqid()));
        $path = "contract-documents/{$contract->id}/document.pdf";
        Storage::disk('local')->put($path, 'PRIVATE-DOCUMENT');
        $document = $contract->documents()->create([
            'document_type' => 'signed',
            'original_name' => 'document.pdf',
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
        ]);

        return [$contract, $document];
    }
}
