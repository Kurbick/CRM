<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Access\PermissionName;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\Feature\Authorization\AuthorizationTestCase;
use ZipArchive;

class InvoiceExportTest extends AuthorizationTestCase
{
    public function test_authorized_user_can_download_word_with_canonical_invoice_content(): void
    {
        $invoice = $this->configuredInvoice();
        $this->actingAsPermissions([PermissionName::InvoicesPrint->value]);

        $response = $this->get(route('invoices.export.word', $invoice))->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('attachment;', $disposition);
        $this->assertStringContainsString('filename=invoice-20-ZL-26.docx', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringNotContainsString('\\', $disposition);

        $documentXml = $this->docxDocumentXml($response->streamedContent());
        foreach (['INVOICE', '20/ZL\\26', 'Snapshot Seller', 'Buyer Company', 'Exportable support service', 'Итого', 'НДС (19%)', 'Всего к оплате', 'Директор', 'СПАСИБО ЗА СОТРУДНИЧЕСТВО!'] as $text) {
            $this->assertStringContainsString($text, $documentXml);
        }
        $this->assertSame(1, substr_count($documentXml, 'M.Y.'));
        foreach (['CBC Sport MMC', '112/ZL-26', '350.00', '17.08.2026'] as $sampleValue) {
            $this->assertStringNotContainsString($sampleValue, $documentXml);
        }
    }

    public function test_authorized_user_can_download_az_excel_with_numeric_snapshot_totals(): void
    {
        $invoice = $this->configuredInvoice();
        $this->actingAsPermissions([PermissionName::InvoicesPrint->value]);

        $response = $this->withSession(['locale' => 'az'])
            ->get(route('invoices.export.excel', $invoice))
            ->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('filename=invoice-20-ZL-26.xlsx', (string) $response->headers->get('Content-Disposition'));

        $spreadsheet = $this->spreadsheet($response->streamedContent());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $this->assertContains('Cəmi', array_column($rows, 'B'));
        $this->assertContains('ƏDV (19%)', array_column($rows, 'B'));
        $this->assertContains('Ödəniləcək məbləğ', array_column($rows, 'B'));
        $this->assertContains('S.W.I.F.T:', array_column($rows, 'A'));
        $this->assertContains($sheet->getStyle('A14')->getAlignment()->getHorizontal(), [Alignment::HORIZONTAL_LEFT, Alignment::HORIZONTAL_GENERAL]);
        $this->assertContains($sheet->getStyle('B14')->getAlignment()->getHorizontal(), [Alignment::HORIZONTAL_LEFT, Alignment::HORIZONTAL_GENERAL]);
        $this->assertFalse($sheet->getStyle('A14')->getAlignment()->getShrinkToFit());
        $this->assertFalse($sheet->getStyle('B14')->getAlignment()->getShrinkToFit());
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $sheet->getStyle('C2')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('D2')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $sheet->getStyle('C5')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('D5')->getAlignment()->getHorizontal());
        $this->assertCount(1, $sheet->getDrawingCollection());
        $this->assertContains('A5:B5', $sheet->getMergeCells());
        $this->assertNotContains('C5:D5', $sheet->getMergeCells());
        $this->assertNotContains('A14:B14', $sheet->getMergeCells());
        $this->assertContains('B14:C14', $sheet->getMergeCells());
        $this->assertNotContains('C14:C14', $sheet->getMergeCells());
        $this->assertContains('B9:C9', $sheet->getMergeCells());
        $this->assertNotContains('A9:A9', $sheet->getMergeCells());
        foreach (['A9', 'A10', 'A11', 'A12', 'A13', 'A14'] as $labelCell) {
            $this->assertContains($sheet->getStyle($labelCell)->getAlignment()->getHorizontal(), [Alignment::HORIZONTAL_LEFT, Alignment::HORIZONTAL_GENERAL]);
            $this->assertSame(0, $sheet->getStyle($labelCell)->getAlignment()->getIndent());
        }
        $this->assertSame(0, $sheet->getStyle('B14')->getAlignment()->getIndent());

        $totals = $this->findRowsByLabel($rows, ['Cəmi', 'ƏDV (19%)', 'Ödəniləcək məbləğ']);
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D'.$totals['Cəmi'])->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D'.$totals['ƏDV (19%)'])->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D'.$totals['Ödəniləcək məbləğ'])->getDataType());
        $this->assertSame(100.0, $sheet->getCell('D'.$totals['Cəmi'])->getValue());
        $this->assertSame(19.0, $sheet->getCell('D'.$totals['ƏDV (19%)'])->getValue());
        $this->assertSame(119.0, $sheet->getCell('D'.$totals['Ödəniləcək məbləğ'])->getValue());
        $lineRow = $this->findRowContaining($rows, 'Exportable support service');
        $this->assertNotFalse($lineRow);
        $this->assertGreaterThan(24.0, $sheet->getRowDimension($lineRow)->getRowHeight());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D'.$lineRow)->getDataType());
        $this->assertSame(100.0, $sheet->getCell('D'.$lineRow)->getValue());
        $this->assertSame(6, $totals['Cəmi'] - $lineRow - 1);
        $this->assertFalse($sheet->getShowGridlines());
        $this->assertFalse($sheet->getPrintGridlines());
        $this->assertSame('', $sheet->getAutoFilter()->getRange());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
        $this->assertSame(PageSetup::ORIENTATION_PORTRAIT, $sheet->getPageSetup()->getOrientation());
        $this->assertTrue($sheet->getPageSetup()->getFitToPage());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToHeight());
        $footerRow = array_search('BİZİMLƏ ƏMƏKDAŞLIQ ETDİYİNİZ ÜÇÜN TƏŞƏKKÜR EDİRİK!', array_column($rows, 'A'), true);
        $this->assertNotFalse($footerRow);
        $footerRow++;
        $this->assertSame('A1:D'.$footerRow, $sheet->getPageSetup()->getPrintArea());
        $directorRow = array_search('Direktor: ____________________', array_column($rows, 'A'), true);
        $this->assertNotFalse($directorRow);
        $directorRow++;
        $this->assertSame(
            Border::BORDER_THIN,
            $sheet->getStyle('D'.$directorRow)->getBorders()->getBottom()->getBorderStyle()
        );
        $this->assertSame('M.Y.', $sheet->getCell('D'.($directorRow + 2))->getValue());
        $this->assertSame('19.00', $invoice->fresh()->vat_amount);
    }

    public function test_ru_excel_buyer_label_does_not_wrap_and_table_bottom_is_closed(): void
    {
        $invoice = $this->configuredInvoice();
        $this->actingAsPermissions([PermissionName::InvoicesPrint->value]);

        $response = $this->get(route('invoices.export.excel', $invoice))->assertOk();
        $sheet = $this->spreadsheet($response->streamedContent())->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        $totalRows = $this->findRowsByLabel($rows, ['Всего к оплате']);
        $totalRow = $totalRows['Всего к оплате'];

        $this->assertSame('Плательщик:', $sheet->getCell('C5')->getValue());
        $this->assertSame('Buyer Company', $sheet->getCell('D5')->getValue());
        $this->assertStringNotContainsString("\n", (string) $sheet->getCell('C5')->getValue());
        $this->assertNotContains('C5:D5', $sheet->getMergeCells());
        $this->assertFalse($sheet->getStyle('C5')->getAlignment()->getWrapText());
        $this->assertFalse($sheet->getStyle('C5')->getAlignment()->getShrinkToFit());
        $this->assertSame(0, $sheet->getStyle('C5')->getAlignment()->getIndent());
        $this->assertSame(Alignment::HORIZONTAL_RIGHT, $sheet->getStyle('C5')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('D5')->getAlignment()->getHorizontal());
        $this->assertSame(Alignment::VERTICAL_CENTER, $sheet->getStyle('C5')->getAlignment()->getVertical());
        $this->assertSame(11.0, (float) $sheet->getStyle('C5')->getFont()->getSize());
        $this->assertSame(-1.0, (float) $sheet->getRowDimension(5)->getRowHeight());
        $this->assertSame(23.0, $sheet->getColumnDimension('B')->getWidth());
        $this->assertSame(25.0, $sheet->getColumnDimension('C')->getWidth());
        $this->assertSame(18.0, $sheet->getColumnDimension('D')->getWidth());
        $this->assertContains("B{$totalRow}:C{$totalRow}", $sheet->getMergeCells());
        foreach (['A', 'B', 'C', 'D'] as $column) {
            $this->assertSame(
                Border::BORDER_THIN,
                $sheet->getStyle("{$column}{$totalRow}")->getBorders()->getBottom()->getBorderStyle()
            );
        }
        $rowsByColumnA = array_column($rows, 'A');
        $this->assertContains('Директор: ____________________', $rowsByColumnA);
        $directorRow = array_search('Директор: ____________________', $rowsByColumnA, true);
        $this->assertNotFalse($directorRow);
        $directorRow++;
        $this->assertSame('М.П.', $sheet->getCell('D'.($directorRow + 2))->getValue());
        $footerRow = array_search('СПАСИБО ЗА СОТРУДНИЧЕСТВО!', $rowsByColumnA, true);
        $this->assertNotFalse($footerRow);
        $footerRow++;
        $this->assertSame('A1:D'.$footerRow, $sheet->getPageSetup()->getPrintArea());
    }

    public function test_vat_neutral_exports_omit_vat_row_and_keep_canonical_total(): void
    {
        $invoice = $this->configuredInvoice(false);
        $this->actingAsPermissions([PermissionName::InvoicesPrint->value]);

        $wordResponse = $this->get(route('invoices.export.word', $invoice))->assertOk();
        $wordXml = $this->docxDocumentXml($wordResponse->streamedContent());
        $this->assertStringNotContainsString('НДС (', $wordXml);
        $this->assertStringContainsString('Всего к оплате', $wordXml);
        $this->assertStringContainsString('100,00 ₼', $wordXml);

        $excelResponse = $this->get(route('invoices.export.excel', $invoice))->assertOk();
        $spreadsheet = $this->spreadsheet($excelResponse->streamedContent());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $labels = array_column($rows, 'B');
        $this->assertNotContains('НДС (19%)', $labels);
        $totalRows = $this->findRowsByLabel($rows, ['Всего к оплате']);
        $this->assertSame(100.0, $spreadsheet->getActiveSheet()->getCell('D'.$totalRows['Всего к оплате'])->getValue());
    }

    public function test_exports_require_print_permission_and_authentication(): void
    {
        $invoice = $this->configuredInvoice();
        $this->actingAsPermissions([PermissionName::InvoicesView->value]);

        foreach (['invoices.export.word', 'invoices.export.excel'] as $routeName) {
            $this->get(route($routeName, $invoice))->assertForbidden();
        }

        $this->actingAsGuest();
        foreach (['invoices.export.word', 'invoices.export.excel'] as $routeName) {
            $this->get(route($routeName, $invoice))->assertRedirect(route('login'));
        }
    }

    private function configuredInvoice(bool $vatEnabled = true): Invoice
    {
        $invoice = $this->invoice('issued', 'EXPORT-INVOICE');
        $organization = Organization::query()->firstOrFail();
        $organization->update([
            'legal_name' => 'ZeroLine Legal Name',
            'bank_name' => 'Snapshot Bank',
            'iban' => 'AZ00SNAPSHOT',
            'bank_correspondent_account' => 'CORR-001',
            'bank_code' => 'BANK-001',
            'bank_voen' => 'BANK-V-001',
            'swift' => 'SWIFT001',
        ]);
        $invoice->company->update([
            'name' => 'Buyer Company',
            'voen' => 'BUYER-001',
            'phone' => '+994 50 000 00 00',
        ]);
        $invoice->forceFill([
            'invoice_number' => '20/ZL\\26',
            'issuer_organization_id' => $organization->id,
            'seller_name' => 'Snapshot Seller',
            'seller_voen' => 'SELLER-001',
            'seller_bank_name' => 'Snapshot Bank',
            'seller_iban' => 'AZ00SNAPSHOT',
            'seller_bank_voen' => 'BANK-V-001',
            'payer_name' => 'Buyer Company',
            'payer_voen' => 'BUYER-SNAPSHOT',
            'subtotal_amount' => '100.00',
            'vat_enabled' => $vatEnabled,
            'vat_rate' => $vatEnabled ? '19.00' : null,
            'vat_amount' => $vatEnabled ? '19.00' : '0.00',
            'total_amount' => $vatEnabled ? '119.00' : '100.00',
        ])->save();
        $invoice->lines()->firstOrFail()->update(['description' => 'Exportable support service']);
        return $invoice->fresh();
    }

    private function docxDocumentXml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-word-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        $archive = new ZipArchive();
        $this->assertTrue($archive->open($path));
        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        unlink($path);

        $this->assertIsString($xml);

        return $xml;
    }

    private function spreadsheet(string $contents): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice-excel-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $spreadsheet = SpreadsheetIOFactory::load($path);
        unlink($path);

        return $spreadsheet;
    }

    /** @param array<int, array<string, mixed>> $rows @param list<string> $labels @return array<string, int> */
    private function findRowsByLabel(array $rows, array $labels): array
    {
        $found = [];
        foreach ($rows as $rowNumber => $row) {
            if (in_array($row['B'] ?? null, $labels, true)) {
                $found[$row['B']] = $rowNumber;
            }
        }

        return $found;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function findRowContaining(array $rows, string $needle): int|false
    {
        foreach ($rows as $rowNumber => $row) {
            if (str_contains((string) ($row['B'] ?? ''), $needle)) {
                return $rowNumber;
            }
        }

        return false;
    }
}
