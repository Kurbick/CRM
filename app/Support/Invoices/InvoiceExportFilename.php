<?php

namespace App\Support\Invoices;

use App\Models\Invoice;

final class InvoiceExportFilename
{
    public function for(Invoice $invoice, string $extension): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_number) ?? '';
        $number = trim($number, '.-_');
        $number = $number !== '' ? $number : (string) $invoice->getKey();

        return 'invoice-'.$number.'.'.$extension;
    }
}
