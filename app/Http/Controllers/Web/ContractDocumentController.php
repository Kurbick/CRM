<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractDocumentController extends Controller
{
    /**
     * Загрузка документа к договору.
     */
    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $validated = $request->validate([
            'document_type' => [
                'required',
                'in:original,signed,other',
            ],

            'document' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,jpg,jpeg,png',
                'extensions:pdf,doc,docx,jpg,jpeg,png',
                'max:10240',
            ],

            'comment' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $file = $request->file('document');

        $path = $file->store(
            "contracts/{$contract->id}/documents",
            'local'
        );

        try {
            $contract->documents()->create([
                'document_type' => $validated['document_type'],
                'original_name' => $file->getClientOriginalName(),
                'file_path'     => $path,
                'mime_type'     => $file->getMimeType(),
                'file_size'     => $file->getSize(),
                'comment'       => $validated['comment'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Документ успешно загружен.');
    }

    /**
     * Скачивание документа.
     */
    public function download(ContractDocument $document): StreamedResponse
    {
        abort_unless(
            Storage::disk('local')->exists($document->file_path),
            404,
            'Файл не найден.'
        );

        return Storage::disk('local')->download(
            $document->file_path,
            $document->original_name
        );
    }

    /**
     * Удаление документа.
     */
    public function destroy(ContractDocument $document): RedirectResponse
    {
        $contract = $document->contract;

        Storage::disk('local')->delete($document->file_path);

        $document->delete();

        return redirect()
            ->route('contracts.show', $contract)
            ->with('success', 'Документ удалён.');
    }
}
