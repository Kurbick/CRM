<?php

namespace Tests\Feature;

use App\Support\Access\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContractDocumentDisplayTest extends AuthorizationTestCase
{
    public function test_new_document_created_at_is_displayed_in_baku_time(): void
    {
        $actor = $this->actingAsPermissions([
            PermissionName::ContractsView->value,
            PermissionName::ContractDocumentsDownload->value,
        ]);
        $contract = $this->contract($this->company('Document display company'));
        $createdAt = CarbonImmutable::create(2031, 2, 3, 8, 9, 10, 'UTC');
        Carbon::setTestNow($createdAt);

        try {
            $document = $contract->documents()->create([
                'document_type' => 'signed',
                'original_name' => 'agreement.pdf',
                'file_path' => 'contract-documents/display/agreement.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 123,
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $response = $this->actingAs($actor)->get(route('contracts.show', $contract));

        $response->assertOk()->assertSee('03/02/2031 12:09');
        $this->assertSame($createdAt->getTimestamp(), $document->fresh()->created_at?->getTimestamp());
    }
}
