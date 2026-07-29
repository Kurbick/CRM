<?php

namespace App\Http\Controllers\Web;

use App\Actions\ContractDocuments\DeleteContractDocument;
use App\Actions\ContractDocuments\StoreContractDocument;
use App\Exceptions\ContractDocumentDeletionException;
use App\Exceptions\ContractDocumentStorageException;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Support\ContractDocuments\ContractDocumentFileType;
use App\Support\ContractDocuments\ContractDocumentPath;
use App\Support\ContractDocuments\SafeDocumentName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractDocumentController extends Controller
{
    public function store(
        Request $request,
        Contract $contract,
        StoreContractDocument $storeDocument
    ): RedirectResponse {
        Gate::authorize('create', [ContractDocument::class, $contract]);

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
                'document' => 'Файл не должен быть пустым.',
            ]);
        }

        if (! SafeDocumentName::isAcceptableOriginalPath($file->getClientOriginalPath())) {
            throw ValidationException::withMessages([
                'document' => 'Имя файла содержит недопустимые символы или слишком длинное.',
            ]);
        }

        $serverExtension = ContractDocumentFileType::serverExtension($file);

        if (
            $serverExtension === null
            || ! SafeDocumentName::extensionMatches($file->getClientOriginalPath(), $serverExtension)
        ) {
            throw ValidationException::withMessages([
                'document' => 'Расширение файла не соответствует его содержимому.',
            ]);
        }

        try {
            $storeDocument->handle(
                $contract,
                $file,
                $validated['document_type'],
                $validated['comment'] ?? null
            );
        } catch (ContractDocumentStorageException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', $exception->getMessage());
        }

        return $this->mutationRedirect($contract)
            ->with('success', 'Документ успешно загружен.');
    }

    public function download(ContractDocument $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        $path = (string) $document->file_path;

        abort_unless(
            ContractDocumentPath::isAllowed($document, $path)
                && Storage::disk(ContractDocumentPath::DISK)->exists($path),
            404,
            'Файл не найден.'
        );

        return Storage::disk(ContractDocumentPath::DISK)->download(
            $path,
            SafeDocumentName::sanitize((string) $document->original_name),
            [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    public function destroy(
        ContractDocument $document,
        DeleteContractDocument $deleteDocument
    ): RedirectResponse {
        Gate::authorize('delete', $document);

        $contract = $document->contract()
            ->select(['id', 'company_id', 'contract_number'])
            ->firstOrFail();

        try {
            $deleteDocument->handle($document);
        } catch (ContractDocumentDeletionException $exception) {
            return $this->mutationRedirect($contract)
                ->with('error', $exception->getMessage());
        }

        return $this->mutationRedirect($contract)
            ->with('success', 'Документ удалён.');
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

        return redirect()->route('dashboard');
    }
}
