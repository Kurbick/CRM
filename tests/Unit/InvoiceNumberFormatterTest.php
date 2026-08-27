<?php

namespace Tests\Unit;

use App\Support\Invoices\InvoiceNumberFormatter;
use PHPUnit\Framework\TestCase;

class InvoiceNumberFormatterTest extends TestCase
{
    public function test_formats_without_leading_zeroes_and_uses_two_digit_year(): void
    {
        $formatter = new InvoiceNumberFormatter();

        $this->assertSame('102/ZL-26', $formatter->format(102, 'ZL', 2026));
        $this->assertSame('1/ZL-27', $formatter->format(1, 'ZL', 2027));
        $this->assertSame('9/ABC-30', $formatter->format(9, 'abc', 2030));
    }
}
