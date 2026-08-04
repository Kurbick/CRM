<?php

namespace App\Support\Invoices;

final class InvoiceSellerSnapshot
{
    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'seller_name' => $this->value(config('invoice.seller.name')),
            'seller_voen' => $this->value(config('invoice.seller.voen')),
            'seller_bank_name' => $this->value(config('invoice.seller.bank_name')),
            'seller_iban' => $this->value(config('invoice.seller.iban')),
            'seller_bank_code' => $this->value(config('invoice.seller.bank_code')),
            'seller_bank_voen' => $this->value(config('invoice.seller.bank_voen')),
            'seller_swift' => $this->value(config('invoice.seller.swift')),
        ];
    }

    private function value(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
