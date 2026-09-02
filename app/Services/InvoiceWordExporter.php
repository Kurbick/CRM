<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Invoices\InvoiceExportFilename;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\TemplateProcessor;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceWordExporter
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private readonly InvoiceExportFilename $filename) {}

    /** @param array<string, mixed> $document */
    public function download(Invoice $invoice, array $document): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-word-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary Word export file.');
        }

        $processor = new TemplateProcessor(resource_path('templates/invoice.docx'));
        $lines = $document['lines'];

        if ($lines === []) {
            $processor->deleteRow('line_no');
        } else {
            $processor->cloneRowAndSetValues('line_no', array_map(
                fn (array $line, int $index): array => [
                    'line_no' => (string) ($index + 1),
                    'line_description' => (string) $line['description'],
                    'line_amount' => $this->formatMoney($line['amount']),
                ],
                $lines,
                array_keys($lines),
            ));
        }

        if (! $invoice->vat_enabled) {
            $processor->deleteRow('vat_label');
        }

        $seller = $document['seller'];
        $buyer = $document['buyer'];
        $processor->setValues([
            'invoice_heading' => __('invoices.print.heading'),
            'issue_date_line' => __('invoices.print.issue_date').': '.$this->formatDate($invoice->issue_date),
            'invoice_number_line' => __('invoices.print.invoice_number').': '.$invoice->invoice_number,
            'seller_name' => $seller['name'] ?? '',
            'seller_tax_line' => filled($seller['voen'] ?? null)
                ? __('invoices.print.voen').': '.$seller['voen']
                : '',
            'account_label' => __('invoices.print.account_short'),
            'bank_label' => __('invoices.print.bank_short'),
            'bank_voen_label' => __('invoices.print.voen_short'),
            'correspondent_label' => __('invoices.print.correspondent_short'),
            'bank_code_label' => __('invoices.print.bank_code_short'),
            'swift_label' => __('invoices.print.swift_short'),
            'seller_account_and_bank' => $this->joinValues($seller['iban'] ?? null, $seller['bank_name'] ?? null),
            'seller_bank_voen_and_correspondent' => $this->joinValues($seller['bank_voen'] ?? null, $seller['correspondent_account'] ?? null),
            'seller_bank_code' => $seller['bank_code'] ?? '',
            'seller_swift' => $seller['swift'] ?? '',
            'buyer_label' => __('invoices.print.buyer').':',
            'buyer_name' => $buyer['name'] ?? '',
            'buyer_voen_line' => filled($buyer['voen'] ?? null)
                ? __('invoices.print.voen').': '.$buyer['voen']
                : '',
            'buyer_phone_line' => filled($buyer['phone'] ?? null)
                ? __('invoices.print.phone').': '.$buyer['phone']
                : '',
            'number_label' => __('invoices.print.number'),
            'description_label' => __('invoices.print.description'),
            'amount_label' => __('invoices.print.amount'),
            'subtotal_label' => __('invoices.print.subtotal'),
            'subtotal_amount' => $this->formatMoney($invoice->subtotal_amount),
            'vat_label' => __('invoices.print.vat', ['rate' => $this->vatRateLabel($invoice)]),
            'vat_amount' => $this->formatMoney($invoice->vat_amount),
            'total_label' => __('invoices.print.total'),
            'total_amount' => $this->formatMoney($invoice->total_amount),
            'director_line' => __('invoices.print.director').': '.__('invoices.print.signature_placeholder'),
            'stamp_label' => __('invoices.print.stamp'),
            'footer' => __('invoices.print.footer'),
        ]);
        $processor->saveAs($path);

        return response()->streamDownload(function () use ($path): void {
            try {
                readfile($path);
            } finally {
                unlink($path);
            }
        }, $this->filename->for($invoice, 'docx'), ['Content-Type' => self::CONTENT_TYPE]);
    }

    private function formatDate(mixed $date): string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y') : '—';
    }

    private function vatRateLabel(Invoice $invoice): string
    {
        return filled($invoice->vat_rate)
            ? rtrim(rtrim((string) $invoice->vat_rate, '0'), '.')
            : '—';
    }

    private function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' ₼';
    }

    private function joinValues(mixed ...$values): string
    {
        return implode(' ', array_values(array_filter(
            $values,
            static fn (mixed $value): bool => filled($value),
        )));
    }
}
