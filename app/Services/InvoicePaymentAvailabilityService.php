<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use LogicException;

class InvoicePaymentAvailabilityService
{
    /**
     * @return array{remaining_minor: int, pending_minor: int, available_minor: int, available_amount: string}
     */
    public function evaluate(Invoice $invoice): array
    {
        $totalMinor = $this->toMinorUnits($invoice->total_amount);

        if ($invoice->relationLoaded('payments')) {
            $confirmedMinor = 0;
            $pendingMinor = 0;

            foreach ($invoice->payments as $payment) {
                if (! $payment instanceof Payment) {
                    throw new LogicException('Invoice payments relation must contain Payment models.');
                }

                if ($payment->status === 'confirmed') {
                    $confirmedMinor += $this->toMinorUnits($payment->amount);
                } elseif ($payment->status === 'pending') {
                    $pendingMinor += $this->toMinorUnits($payment->amount);
                }
            }
        } else {
            $confirmedMinor = $this->toMinorUnits(
                $invoice->payments()->where('status', 'confirmed')->sum('amount')
            );
            $pendingMinor = $this->toMinorUnits(
                $invoice->payments()->where('status', 'pending')->sum('amount')
            );
        }

        $remainingMinor = max($totalMinor - $confirmedMinor, 0);
        $availableMinor = max($remainingMinor - $pendingMinor, 0);

        return [
            'remaining_minor' => $remainingMinor,
            'pending_minor' => $pendingMinor,
            'available_minor' => $availableMinor,
            'available_amount' => $this->fromMinorUnits($availableMinor),
        ];
    }

    /**
     * API pending reservations ignore non-positive legacy rows so they can never
     * increase or offset the capacity reserved by active payments.
     *
     * @return array{remaining_minor: int, pending_minor: int, available_minor: int, available_amount: string}
     */
    public function evaluatePendingCreation(Invoice $invoice): array
    {
        $reserved = $invoice->payments()
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'confirmed' AND amount > 0 THEN amount ELSE 0 END), 0) AS confirmed_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' AND amount > 0 THEN amount ELSE 0 END), 0) AS pending_amount")
            ->toBase()
            ->first();

        $totalMinor = $this->toMinorUnits($invoice->total_amount);
        $confirmedMinor = $this->toMinorUnits($reserved?->confirmed_amount ?? '0.00');
        $pendingMinor = $this->toMinorUnits($reserved?->pending_amount ?? '0.00');
        $remainingMinor = max($totalMinor - $confirmedMinor, 0);
        $availableMinor = max($remainingMinor - $pendingMinor, 0);

        return [
            'remaining_minor' => $remainingMinor,
            'pending_minor' => $pendingMinor,
            'available_minor' => $availableMinor,
            'available_amount' => $this->fromMinorUnits($availableMinor),
        ];
    }

    public function toMinorUnits(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new LogicException("Invalid monetary amount: {$value}");
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    /** @param iterable<mixed> $amounts */
    public function sumToMinorUnits(iterable $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $this->toMinorUnits($amount);
        }

        return $total;
    }

    public function fromMinorUnits(int $amount): string
    {
        $negative = $amount < 0;
        $absolute = abs($amount);
        $decimal = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$decimal : $decimal;
    }

    public function formatMinorUnits(int $amount): string
    {
        $negative = $amount < 0;
        $absolute = abs($amount);
        $whole = number_format(intdiv($absolute, 100), 0, ',', ' ');
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.','.$fraction.' ₼';
    }
}
