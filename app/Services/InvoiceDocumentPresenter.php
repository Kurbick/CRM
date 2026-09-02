<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Invoices\InvoiceSellerSnapshot;

final class InvoiceDocumentPresenter
{
    public function __construct(
        private readonly InvoicePrintPresenter $printPresenter,
        private readonly InvoiceSellerSnapshot $sellerSnapshot,
    ) {}

    /**
     * @return array{
     *     seller: array<string, ?string>,
     *     buyer: array{name: ?string, voen: ?string, phone: ?string},
     *     lines: list<array{description: string, amount: mixed}>,
     *     empty_row_count: int,
     * }
     */
    public function present(Invoice $invoice): array
    {
        $lines = $this->printPresenter->lines($invoice);
        $sellerFallback = $this->sellerSnapshot->legacyFallback();
        $organization = $invoice->issuerOrganization;
        $sellerValue = static function (mixed $snapshot, mixed $organizationValue, mixed $fallback): ?string {
            foreach ([$snapshot, $organizationValue, $fallback] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return null;
        };

        return [
            'seller' => [
                'name' => $sellerValue($invoice->seller_name, $organization?->name, $sellerFallback['seller_name']),
                'legal_name' => $organization?->legal_name,
                'voen' => $sellerValue($invoice->seller_voen, $organization?->voen, $sellerFallback['seller_voen']),
                'bank_name' => $sellerValue($invoice->seller_bank_name, $organization?->bank_name, $sellerFallback['seller_bank_name']),
                'iban' => $sellerValue($invoice->seller_iban, $organization?->iban, $sellerFallback['seller_iban']),
                'correspondent_account' => $organization?->bank_correspondent_account,
                'bank_code' => $sellerValue($invoice->seller_bank_code, $organization?->bank_code, $sellerFallback['seller_bank_code']),
                'bank_voen' => $sellerValue($invoice->seller_bank_voen, $organization?->bank_voen, $sellerFallback['seller_bank_voen']),
                'swift' => $sellerValue($invoice->seller_swift, $organization?->swift, $sellerFallback['seller_swift']),
            ],
            'buyer' => [
                'name' => $invoice->payer_name ?: $invoice->company?->name,
                'voen' => $invoice->payer_voen ?: $invoice->company?->voen,
                'phone' => $invoice->company?->phone,
            ],
            'lines' => $lines,
            'empty_row_count' => max(0, 7 - count($lines)),
        ];
    }
}
