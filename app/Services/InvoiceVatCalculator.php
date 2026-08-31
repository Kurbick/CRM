<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

final class InvoiceVatCalculator
{
    public function __construct(private readonly InvoicePaymentAvailabilityService $money) {}

    /** @return array{vat_enabled: bool, vat_rate: ?string, subtotal_amount: string, vat_amount: string, total_amount: string} */
    public function snapshotForOrganization(Organization $organization, int $subtotalMinor): array
    {
        $enabled = (bool) $organization->is_vat_payer;
        $rate = $enabled ? $this->rateBasisPoints($organization->vat_rate) : null;
        $vatMinor = $enabled ? $this->calculateVatMinor($subtotalMinor, $rate) : 0;

        return $this->snapshot($subtotalMinor, $enabled, $rate, $vatMinor);
    }

    /** @return array{vat_enabled: bool, vat_rate: ?string, subtotal_amount: string, vat_amount: string, total_amount: string} */
    public function recalculateFromInvoice(Invoice $invoice, int $subtotalMinor): array
    {
        $enabled = (bool) $invoice->vat_enabled;
        $rate = $enabled ? $this->rateBasisPoints($invoice->vat_rate, false) : null;
        $vatMinor = $enabled ? $this->calculateVatMinor($subtotalMinor, $rate) : 0;

        return $this->snapshot($subtotalMinor, $enabled, $rate, $vatMinor);
    }

    public function toRateBasisPoints(mixed $rate): ?int
    {
        return $this->parseRate($rate);
    }

    private function rateBasisPoints(mixed $rate, bool $validation = true): ?int
    {
        $basisPoints = $this->parseRate($rate);
        if ($basisPoints === null || $basisPoints <= 0 || $basisPoints > 10000) {
            if ($validation) {
                throw ValidationException::withMessages([
                    'organization' => __('organizations.errors.vat_rate_missing'),
                ]);
            }

            throw new \LogicException('Invoice VAT snapshot contains an invalid VAT rate.');
        }

        return $basisPoints;
    }

    private function parseRate(mixed $rate): ?int
    {
        $value = trim((string) ($rate ?? ''));
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function calculateVatMinor(int $subtotalMinor, int $rateBasisPoints): int
    {
        if ($subtotalMinor < 0) {
            throw new \LogicException('Invoice subtotal cannot be negative.');
        }

        return intdiv(($subtotalMinor * $rateBasisPoints) + 5000, 10000);
    }

    /** @return array{vat_enabled: bool, vat_rate: ?string, subtotal_amount: string, vat_amount: string, total_amount: string} */
    private function snapshot(int $subtotalMinor, bool $enabled, ?int $rateBasisPoints, int $vatMinor): array
    {
        $totalMinor = $subtotalMinor + $vatMinor;

        return [
            'vat_enabled' => $enabled,
            'vat_rate' => $rateBasisPoints === null ? null : $this->money->fromMinorUnits($rateBasisPoints),
            'subtotal_amount' => $this->money->fromMinorUnits($subtotalMinor),
            'vat_amount' => $this->money->fromMinorUnits($vatMinor),
            'total_amount' => $this->money->fromMinorUnits($totalMinor),
        ];
    }
}
