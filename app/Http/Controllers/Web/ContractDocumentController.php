<?php

namespace App\Http\Controllers\Web;

use App\Actions\ContractDocuments\DeleteContractDocument;
use App\Actions\ContractDocuments\StoreContractDocument;
use App\Exceptions\ContractDocumentDeletionException;
use App\Exceptions\ContractDocumentStorageException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Support\Access\PermissionName;
use App\Support\ContractDocuments\ContractDocumentFileType;
use App\Support\ContractDocuments\ContractDocumentPath;
use App\Support\ContractDocuments\SafeDocumentName;
use App\Support\Navigation\AuthorizedLandingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContractDocumentController extends Controller
{
    public function store(
        Request $request,
        string $contract,
        StoreContractDocument $storeDocument
    ): RedirectResponse {
        Gate::authorize(PermissionName::ContractDocumentsUpload->value);

        $contract = Contract::query()->findOrFail($contract);

        $validated = $request->validate([
            'document_type' => ['required', 'in:original,signed,other'],
            'document' => [
                'required',
                File::types(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'])
                    ->max(10 * 1024),
                'extensions:pdf,doc,docx,jpg,jpeg,png',
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('document');

        if ($file->getSize() === false || $file->getSize() <= 0) {
            throw ValidationException::withMessages([
                'document' => __('contracts.documents.errors.empty'),
            ]);
        }

        if (! SafeDocumentName::isAcceptableOriginalPath($file->getClientOriginalPath())) {
            throw ValidationException::withMessages([
                'document' => __('contracts.documents.errors.name'),
            ]);
        }

        $serverExtension = ContractDocumentFileType::serverExtension($file);

        if (
            $serverExtension === null
            || ! SafeDocumentName::extensionMatches($file->getClientOriginalPath(), $serverExtension)
        ) {
            throw ValidationException::withMessages([
                'document' => __('contracts.documents.errors.extension'),
            ]);
        }

        try {
            $storeDocument->handle(
                $contract,
                $file,
                $validated['document_type'],
                $validated['comment'] ?? null,
                $request->user(),
            );
        } catch (ContractDocumentStorageException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', __('contracts.documents.errors.storage'));
        }

        return $this->mutationRedirect($contract)
            ->with('success', __('contracts.documents.flash.uploaded'));
    }

    public function download(string $document): StreamedResponse
    {
        Gate::authorize(PermissionName::ContractDocumentsDownload->value);

        $document = ContractDocument::query()->findOrFail($document);

        $path = (string) $document->file_path;

        abort_unless(ContractDocumentPath::isAllowed($document, $path), 404, __('contracts.documents.errors.not_found'));

        try {
            $stream = Storage::disk(ContractDocumentPath::DISK)->readStream($path);
        } catch (Throwable) {
            abort(404, __('contracts.documents.errors.not_found'));
        }

        abort_unless(is_resource($stream), 404, __('contracts.documents.errors.not_found'));

        return response()->streamDownload(
            static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            },
            SafeDocumentName::sanitize((string) $document->original_name),
            [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function destroy(
        string $document,
        DeleteContractDocument $deleteDocument
    ): RedirectResponse {
        Gate::authorize(PermissionName::ContractDocumentsDelete->value);

        $document = ContractDocument::query()->findOrFail($document);

        $contract = $document->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        try {
            $deleteDocument->handle($document, auth()->user());
        } catch (ContractDocumentDeletionException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', __('contracts.documents.errors.delete_storage'));
        }

        return $this->mutationRedirect($contract)
            ->with('success', __('contracts.documents.flash.deleted'));
    }

    private function mutationRedirect(Contract $contract): RedirectResponse
    {
        if (Gate::allows('view', $contract)) {
            return redirect()->route('contracts.show', $contract);
        }

        $contract->loadMissing('company:id,name');

        if (Gate::allows('view', $contract->company)) {
            return redirect()->route('companies.show', $contract->company);
        }

        return redirect()->to(app(AuthorizedLandingPage::class)->url(auth()->user()));
    }
}
