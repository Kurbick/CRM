<?php

namespace Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;
use ZipArchive;

class InvoiceWordTemplateTypographyTest extends TestCase
{
    /** @var array<string, array{size: string, line: string, bold: bool}> */
    private const TYPOGRAPHY = [
        '${invoice_heading}' => ['size' => '52', 'line' => '240', 'bold' => true],
        '${issue_date_line}' => ['size' => '24', 'line' => '324', 'bold' => false],
        '${invoice_number_line}' => ['size' => '24', 'line' => '324', 'bold' => false],
        '${seller_name}' => ['size' => '24', 'line' => '300', 'bold' => true],
        '${seller_tax_line}' => ['size' => '24', 'line' => '300', 'bold' => true],
        '${account_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${bank_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${bank_voen_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${correspondent_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${bank_code_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${swift_label}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${seller_account_and_bank}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${seller_bank_voen_and_correspondent}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${seller_bank_code}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${seller_swift}' => ['size' => '20', 'line' => '300', 'bold' => false],
        '${buyer_label}' => ['size' => '20', 'line' => '324', 'bold' => true],
        '${buyer_voen_label}' => ['size' => '20', 'line' => '324', 'bold' => true],
        '${buyer_phone_label}' => ['size' => '20', 'line' => '324', 'bold' => true],
        '${buyer_name}' => ['size' => '20', 'line' => '324', 'bold' => false],
        '${buyer_voen_line}' => ['size' => '20', 'line' => '324', 'bold' => false],
        '${buyer_phone_line}' => ['size' => '20', 'line' => '324', 'bold' => false],
        '${number_label}' => ['size' => '24', 'line' => '276', 'bold' => true],
        '${description_label}' => ['size' => '24', 'line' => '276', 'bold' => true],
        '${amount_label}' => ['size' => '24', 'line' => '276', 'bold' => true],
        '${line_no}' => ['size' => '24', 'line' => '276', 'bold' => false],
        '${line_description}' => ['size' => '24', 'line' => '276', 'bold' => false],
        '${line_amount}' => ['size' => '24', 'line' => '276', 'bold' => false],
        '${subtotal_label}' => ['size' => '24', 'line' => '240', 'bold' => true],
        '${subtotal_amount}' => ['size' => '24', 'line' => '240', 'bold' => false],
        '${vat_label}' => ['size' => '24', 'line' => '240', 'bold' => true],
        '${vat_amount}' => ['size' => '24', 'line' => '240', 'bold' => false],
        '${total_label}' => ['size' => '24', 'line' => '240', 'bold' => true],
        '${total_amount}' => ['size' => '24', 'line' => '240', 'bold' => true],
        '${director_line}' => ['size' => '24', 'line' => '276', 'bold' => false],
        'M.Y.' => ['size' => '20', 'line' => '250', 'bold' => false],
        '${footer}' => ['size' => '20', 'line' => '276', 'bold' => true],
    ];

    public function test_template_typography_matches_print_reference_contract(): void
    {
        $archive = new ZipArchive();
        $this->assertTrue($archive->open(resource_path('templates/invoice.docx')));

        $document = new DOMDocument();
        $this->assertTrue($document->loadXML((string) $archive->getFromName('word/document.xml')));
        $styles = new DOMDocument();
        $this->assertTrue($styles->loadXML((string) $archive->getFromName('word/styles.xml')));
        $archive->close();

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $stylesXPath = new DOMXPath($styles);
        $stylesXPath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach (self::TYPOGRAPHY as $placeholder => $expected) {
            $paragraphs = $xpath->query('//w:p[.//w:t = "'.addslashes($placeholder).'" ]');
            $this->assertNotFalse($paragraphs);
            $this->assertCount(1, $paragraphs, $placeholder);

            /** @var DOMElement $paragraph */
            $paragraph = $paragraphs->item(0);
            $spacing = $xpath->query('./w:pPr/w:spacing', $paragraph)->item(0);
            $run = $xpath->query('./w:r[.//w:t != ""]', $paragraph)->item(0);
            $runProperties = $run instanceof DOMElement
                ? $xpath->query('./w:rPr', $run)->item(0)
                : null;

            $this->assertInstanceOf(DOMElement::class, $spacing, $placeholder);
            $this->assertSame($expected['line'], $spacing->getAttribute('w:line'));
            $this->assertInstanceOf(DOMElement::class, $runProperties, $placeholder);
            $size = $xpath->query('./w:sz', $runProperties)->item(0);
            $this->assertInstanceOf(DOMElement::class, $size, $placeholder);
            $this->assertSame($expected['size'], $size->getAttribute('w:val'));

            $style = $xpath->query('./w:pPr/w:pStyle', $paragraph)->item(0);
            $styleBold = $style instanceof DOMElement
                && $stylesXPath->query('//w:style[@w:styleId = "'.$style->getAttribute('w:val').'" ]/w:rPr/w:b')->length > 0;
            $effectiveBold = $xpath->query('./w:b', $runProperties)->length > 0 || $styleBold;
            $this->assertSame($expected['bold'], $effectiveBold, $placeholder);
        }
    }

    public function test_template_geometry_matches_current_print_contract(): void
    {
        $archive = new ZipArchive();
        $this->assertTrue($archive->open(resource_path('templates/invoice.docx')));

        $document = new DOMDocument();
        $this->assertTrue($document->loadXML((string) $archive->getFromName('word/document.xml')));
        $archive->close();

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $section = $xpath->query('/w:document/w:body/w:sectPr')->item(0);
        $this->assertInstanceOf(DOMElement::class, $section);
        $this->assertSame('11906', $xpath->evaluate('string(./w:pgSz/@w:w)', $section));
        $this->assertSame('16838', $xpath->evaluate('string(./w:pgSz/@w:h)', $section));
        $this->assertSame('1080', $xpath->evaluate('string(./w:pgMar/@w:left)', $section));
        $this->assertSame('1080', $xpath->evaluate('string(./w:pgMar/@w:right)', $section));
        $this->assertSame('720', $xpath->evaluate('string(./w:pgMar/@w:top)', $section));
        $this->assertSame('720', $xpath->evaluate('string(./w:pgMar/@w:bottom)', $section));

        $tables = $xpath->query('/w:document/w:body/w:tbl');
        $this->assertNotFalse($tables);
        $this->assertCount(3, $tables);

        $header = $tables->item(0);
        $this->assertSame('9635', $xpath->evaluate('string(./w:tblPr/w:tblW/@w:w)', $header));
        $this->assertSame(['1353', '4315', '214', '1389', '2364'], $this->values($xpath, './w:tblGrid/w:gridCol/@w:w', $header));
        $this->assertSame(['1699', '1133', '1500'], $this->values($xpath, './w:tr/w:trPr/w:trHeight/@w:val', $header));

        $details = $xpath->query('./w:tr[3]', $header)->item(0);
        $this->assertSame('1389', $xpath->evaluate('string(./w:tc[4]/w:tcPr/w:tcW/@w:w)', $details));
        $this->assertSame('2364', $xpath->evaluate('string(./w:tc[5]/w:tcPr/w:tcW/@w:w)', $details));
        $this->assertSame([
            '${buyer_label}', '${buyer_voen_label}', '${buyer_phone_label}',
        ], $this->values($xpath, './w:tc[4]/w:p/w:r/w:t', $details));
        $this->assertSame([
            '${buyer_name}', '${buyer_voen_line}', '${buyer_phone_line}',
        ], $this->values($xpath, './w:tc[5]/w:p/w:r/w:t', $details));

        $items = $tables->item(1);
        $this->assertSame('9561', $xpath->evaluate('string(./w:tblPr/w:tblW/@w:w)', $items));
        $this->assertSame('left', $xpath->evaluate('string(./w:tblPr/w:jc/@w:val)', $items));
        $this->assertSame(['820', '6875', '1866'], $this->values($xpath, './w:tblGrid/w:gridCol/@w:w', $items));
        $this->assertSame(['398', '398', '510', '510', '510', '510', '510', '510', '510', '510', '510'], $this->values($xpath, './w:tr/w:trPr/w:trHeight/@w:val', $items));
        $this->assertSame('single', $xpath->evaluate('string(./w:tr[1]/w:tc[1]/w:tcPr/w:tcBorders/w:bottom/@w:val)', $items));
        $this->assertSame('03B7EB', $xpath->evaluate('string(./w:tr[1]/w:tc[1]/w:tcPr/w:tcBorders/w:bottom/@w:color)', $items));
        $this->assertSame('0', $xpath->evaluate('string(./w:tr[3]/w:tc[2]/w:tcPr/w:tcMar/w:top/@w:w)', $items));
        $this->assertSame('85', $xpath->evaluate('string(./w:tr[2]/w:tc[2]/w:tcPr/w:tcMar/w:left/@w:w)', $items));

        $signature = $tables->item(2);
        $this->assertSame('7224', $xpath->evaluate('string(./w:tblPr/w:tblW/@w:w)', $signature));
        $this->assertSame(['4674', '2550'], $this->values($xpath, './w:tblGrid/w:gridCol/@w:w', $signature));
        $this->assertSame(['1700', '283', '283', '776'], $this->values($xpath, './w:tr/w:trPr/w:trHeight/@w:val', $signature));
        $this->assertSame('0', $xpath->evaluate('string(./w:tblPr/w:tblCellMar/w:left/@w:w)', $signature));
        $this->assertSame('single', $xpath->evaluate('string(./w:tr[2]/w:tc[2]/w:tcPr/w:tcBorders/w:bottom/@w:val)', $signature));
        $this->assertSame('6', $xpath->evaluate('string(./w:tr[2]/w:tc[2]/w:tcPr/w:tcBorders/w:bottom/@w:sz)', $signature));

        $finalParagraph = $xpath->query('/w:document/w:body/w:p[last()]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $finalParagraph);
        $this->assertSame('0', $xpath->evaluate('string(./w:pPr/w:spacing/@w:before)', $finalParagraph));
        $this->assertSame('0', $xpath->evaluate('string(./w:pPr/w:spacing/@w:after)', $finalParagraph));
        $this->assertSame('20', $xpath->evaluate('string(./w:pPr/w:spacing/@w:line)', $finalParagraph));
        $this->assertSame('exact', $xpath->evaluate('string(./w:pPr/w:spacing/@w:lineRule)', $finalParagraph));
        $this->assertSame('2', $xpath->evaluate('string(./w:pPr/w:rPr/w:sz/@w:val)', $finalParagraph));
        $this->assertSame(1, $xpath->query('./w:pPr/w:rPr/w:vanish', $finalParagraph)->length);
    }

    /** @return list<string> */
    private function values(DOMXPath $xpath, string $expression, DOMElement $context): array
    {
        $nodes = $xpath->query($expression, $context);
        $this->assertNotFalse($nodes);

        return array_values(array_filter(array_map(
            static fn (\DOMNode $node): string => $node->nodeValue ?? '',
            iterator_to_array($nodes),
        ), static fn (string $value): bool => $value !== ''));
    }
}
