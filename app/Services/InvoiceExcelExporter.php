<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Invoices\InvoiceExportFilename;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceExcelExporter
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY_FORMAT = '#,##0.00 "₼"';
    private const TABLE_HEADER_COLOR = '82D2F5';
    private const BORDER_COLOR = 'A6A6A6';

    public function __construct(private readonly InvoiceExportFilename $filename) {}

    /** @param array<string, mixed> $document */
    public function download(Invoice $invoice, array $document): StreamedResponse
    {
        $filename = $this->filename->for($invoice, 'xlsx');

        return response()->streamDownload(function () use ($invoice, $document): void {
            $writer = new Xlsx($this->build($invoice, $document));
            $writer->save('php://output');
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }

    /** @param array<string, mixed> $document */
    private function build(Invoice $invoice, array $document): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('invoices.print.heading'));
        $sheet->setShowGridlines(false);
        $sheet->setPrintGridlines(false);

        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getColumnDimension('A')->setWidth(7);
        $sheet->getColumnDimension('B')->setWidth(23);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('D')->setWidth(18);

        $pageSetup = $sheet->getPageSetup();
        $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setHorizontalCentered(true);
        $sheet->getPageMargins()
            ->setTop(0.5)
            ->setRight(0.4)
            ->setBottom(0.5)
            ->setLeft(0.4)
            ->setHeader(0.2)
            ->setFooter(0.2);

        $seller = $document['seller'];
        $buyer = $document['buyer'];
        $this->addLogo($sheet);

        $this->setText($sheet, 'D1', __('invoices.print.heading'));
        $sheet->getStyle('D1')->getFont()->setBold(true)->setSize(22);
        $sheet->getStyle('D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->setLabelValue($sheet, 2, 'C', 'D', $this->withColon(__('invoices.print.invoice_number')), $invoice->invoice_number);
        $this->setLabelValue($sheet, 3, 'C', 'D', $this->withColon(__('invoices.print.issue_date')), $this->formatDate($invoice->issue_date));
        $sheet->getRowDimension(1)->setRowHeight(60);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);

        $this->mergeText($sheet, 'A5:B5', 'A5', $this->labelValue(__('invoices.print.supplier'), $seller['name'] ?? ''));
        if (app()->getLocale() === 'ru') {
            $this->setLabelValue($sheet, 5, 'C', 'D', $this->withColon(__('invoices.print.buyer')), $buyer['name'] ?? '');
        } else {
            $this->mergeText($sheet, 'C5:D5', 'C5', $this->labelValue(__('invoices.print.buyer'), $buyer['name'] ?? ''));
        }
        $this->mergeText($sheet, 'A6:B6', 'A6', $this->labelValue(__('invoices.print.voen'), $seller['voen'] ?? ''));
        $this->mergeText($sheet, 'C6:D6', 'C6', $this->labelValue(__('invoices.print.voen'), $buyer['voen'] ?? ''));
        $this->mergeText($sheet, 'A7:B7', 'A7', $this->labelValue($this->sellerLabel('legal_name'), $seller['legal_name'] ?? ''));
        $this->mergeText($sheet, 'C7:D7', 'C7', $this->labelValue(__('invoices.print.phone'), $buyer['phone'] ?? ''));

        $bankRow = 9;
        foreach (['bank_name', 'iban', 'bank_voen', 'correspondent_account', 'bank_code', 'swift'] as $key) {
            if (filled($seller[$key] ?? null)) {
                $this->setMergedLabelValue($sheet, $bankRow, 'A:B', 'C:D', $this->withColon($this->sellerLabel($key)), $seller[$key]);
                $bankRow++;
            }
        }

        $headerRow = max(17, $bankRow + 1);
        $this->mergeText($sheet, "B{$headerRow}:C{$headerRow}", "B{$headerRow}", __('invoices.print.description'));
        $this->setText($sheet, "A{$headerRow}", __('invoices.print.number'));
        $this->setText($sheet, "D{$headerRow}", __('invoices.print.amount'));
        $sheet->getRowDimension($headerRow)->setRowHeight(25);
        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->applyFromArray($this->headerStyle());

        $lineRow = $headerRow + 1;
        foreach ($document['lines'] as $index => $line) {
            $description = (string) $line['description'];
            $this->setNumeric($sheet, "A{$lineRow}", $index + 1);
            $this->mergeText($sheet, "B{$lineRow}:C{$lineRow}", "B{$lineRow}", $description);
            $this->setNumeric($sheet, "D{$lineRow}", $line['amount']);
            $sheet->getRowDimension($lineRow)->setRowHeight($this->lineRowHeight($description));
            $lineRow++;
        }

        for ($index = 0; $index < $document['empty_row_count']; $index++) {
            $sheet->mergeCells("B{$lineRow}:C{$lineRow}");
            $sheet->getRowDimension($lineRow)->setRowHeight(21);
            $lineRow++;
        }

        $totalStartRow = $lineRow;
        if ($invoice->vat_enabled) {
            $this->addTotalRow($sheet, $lineRow++, __('invoices.print.subtotal'), $invoice->subtotal_amount, false);
            $this->addTotalRow($sheet, $lineRow++, __('invoices.print.vat', ['rate' => $this->vatRateLabel($invoice)]), $invoice->vat_amount, false);
        }
        $grandTotalRow = $lineRow;
        $this->addTotalRow($sheet, $grandTotalRow, __('invoices.print.total'), $invoice->total_amount, true);

        $tableRange = "A{$headerRow}:D{$lineRow}";
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB(self::BORDER_COLOR);
        $sheet->getStyle("A{$headerRow}:A{$lineRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$headerRow}:C{$lineRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle("D{$headerRow}:D{$lineRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("D{$headerRow}:D{$lineRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle("B{$totalStartRow}:C{$lineRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A1:D{$lineRow}")->getFont()->setName('Arial');

        $sheet->freezePane("A".($headerRow + 1));
        $pageSetup->setPrintArea("A1:D{$lineRow}");

        if (app()->getLocale() === 'ru') {
            $sheet->getStyle('C5')->getAlignment()
                ->setWrapText(false)
                ->setShrinkToFit(true)
                ->setIndent(0)
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        $sheet->getStyle("A{$grandTotalRow}:D{$grandTotalRow}")->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER_COLOR],
                ],
            ],
        ]);

        return $spreadsheet;
    }

    private function addLogo(Worksheet $sheet): void
    {
        $logoPath = public_path('images/zeroline-logo.png');
        if (! is_file($logoPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('ZeroLine logo');
        $drawing->setDescription('ZeroLine logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(70);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(2);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($sheet);
    }

    private function addTotalRow(Worksheet $sheet, int $row, string $label, mixed $amount, bool $grandTotal): void
    {
        $this->mergeText($sheet, "B{$row}:C{$row}", "B{$row}", $label);
        $this->setNumeric($sheet, "D{$row}", $amount);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $sheet->getStyle("B{$row}:D{$row}")->getFont()->setBold($grandTotal);
    }

    private function setLabelValue(Worksheet $sheet, int $row, string $labelColumn, string $valueColumn, string $label, mixed $value): void
    {
        $this->setText($sheet, "{$labelColumn}{$row}", $label);
        $this->setText($sheet, "{$valueColumn}{$row}", $value ?? '');
        $sheet->getStyle("{$labelColumn}{$row}")->getFont()->setBold(true);
    }

    private function setMergedLabelValue(Worksheet $sheet, int $row, string $labelRange, string $valueRange, string $label, mixed $value): void
    {
        $labelStart = explode(':', $labelRange, 2)[0];
        $valueStart = explode(':', $valueRange, 2)[0];
        $this->mergeText($sheet, $this->rangeForRow($labelRange, $row), "{$labelStart}{$row}", $label);
        $this->mergeText($sheet, $this->rangeForRow($valueRange, $row), "{$valueStart}{$row}", $value ?? '');
        $sheet->getStyle("{$labelStart}{$row}")->getFont()->setBold(true);
    }

    private function rangeForRow(string $range, int $row): string
    {
        $columns = explode(':', $range, 2);
        if (count($columns) !== 2) {
            return $range;
        }

        return "{$columns[0]}{$row}:{$columns[1]}{$row}";
    }

    private function mergeText(Worksheet $sheet, string $range, string $cell, mixed $value): void
    {
        $sheet->mergeCells($range);
        $this->setText($sheet, $cell, $value);
    }

    /** @return array<string, mixed> */
    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'name' => 'Arial', 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::TABLE_HEADER_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private function setText(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
    }

    private function setNumeric(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
    }

    private function lineRowHeight(string $description): float
    {
        $length = max(1, mb_strlen($description));
        $lines = max(1, (int) ceil($length / 52));

        return $lines === 1 ? 24.0 : 24.0 + (($lines - 1) * 15.0);
    }

    private function withColon(string $label): string
    {
        return str_ends_with(rtrim($label), ':') ? $label : rtrim($label).':';
    }

    private function labelValue(string $label, mixed $value): string
    {
        return $this->withColon($label).' '.(string) ($value ?? '');
    }

    private function sellerLabel(string $key): string
    {
        return match ($key) {
            'legal_name' => __('invoices.print.legal_name'),
            'voen' => __('invoices.print.voen'),
            'bank_name' => __('invoices.print.bank_short'),
            'iban' => __('invoices.print.account_short'),
            'bank_voen' => __('invoices.print.voen_short'),
            'correspondent_account' => __('invoices.print.correspondent_short'),
            'bank_code' => __('invoices.print.bank_code_short'),
            'swift' => __('invoices.print.swift_short'),
            default => $key,
        };
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
}
