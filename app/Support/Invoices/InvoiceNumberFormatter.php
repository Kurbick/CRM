<?php

namespace App\Support\Invoices;

final class InvoiceNumberFormatter
{
    public function format(int $sequence, string $code, int $year): string
    {
        if ($sequence < 1 || $year < 1) {
            throw new \InvalidArgumentException('Invoice number sequence and year must be positive.');
        }

        return $sequence.'/'.strtoupper(trim($code)).'-'.substr((string) $year, -2);
    }
}
