<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceDocumentPresenter;
use App\Services\InvoiceExcelExporter;
use App\Services\InvoiceWordExporter;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceExportController extends Controller
{
    public function __construct(
        private readonly InvoiceDocumentPresenter $documentPresenter,
        private readonly InvoiceWordExporter $wordExporter,
        private readonly InvoiceExcelExporter $excelExporter,
    ) {}

    public function word(Invoice $invoice): StreamedResponse
    {
        $document = $this->document($invoice);

        return $this->wordExporter->download($invoice, $document);
    }

    public function excel(Invoice $invoice): StreamedResponse
    {
        $document = $this->document($invoice);

        return $this->excelExporter->download($invoice, $document);
    }

    /** @return array<string, mixed> */
    private function document(Invoice $invoice): array
    {
        Gate::authorize('print', $invoice);

        $invoice->load([
            'company',
            'contract',
            'issuerOrganization',
            'lines',
        ]);

        return $this->documentPresenter->present($invoice);
    }
}
