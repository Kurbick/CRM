<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Order;
use App\Models\Subscription;

final class CompanyActivitySnapshot
{
    /** @return array<string, mixed> */
    public static function subject(Order|Subscription $subject, Contract $contract): array
    {
        $name = trim((string) ($subject->title ?: $subject->serviceType?->name));
        $amount = $subject instanceof Subscription ? $subject->amount : $subject->price;

        return array_filter([
            'subject_type' => $subject instanceof Subscription ? 'subscription' : 'one_time',
            'subject_name' => $name !== '' ? $name : null,
            'contract_number' => self::text($contract->contract_number),
            'amount_minor' => self::minorAmount($amount),
            'billing_period' => $subject instanceof Subscription
                ? self::text($subject->billing_period)
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function contract(Contract $contract): array
    {
        return array_filter([
            'contract_number' => self::text($contract->contract_number),
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'status' => self::text($contract->status),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public static function companyFor(Contract $contract): Company
    {
        if ($contract->relationLoaded('company') && $contract->company instanceof Company) {
            return $contract->company;
        }

        return (new Company)->forceFill(['id' => $contract->company_id]);
    }

    public static function minorAmount(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $normalized = trim(str_replace(',', '.', (string) $amount));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');

        if ($whole === '' || ! ctype_digit($whole) || ! ctype_digit(str_pad($fraction, 2, '0'))) {
            return null;
        }

        $minor = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$minor : $minor;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
