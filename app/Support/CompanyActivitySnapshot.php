<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use DateTimeInterface;

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

    /** @return array<string, mixed> */
    public static function invoice(Invoice $invoice, ?Contract $contract = null): array
    {
        $contractNumber = $contract?->contract_number;
        if ($contractNumber === null && $invoice->relationLoaded('contract')) {
            $contractNumber = $invoice->contract?->contract_number;
        }
        if ($contractNumber === null) {
            $contractNumber = $invoice->contract_reference;
        }

        return array_filter([
            'invoice_number' => self::text($invoice->invoice_number),
            'status' => self::text($invoice->status),
            'amount_minor' => self::minorAmount($invoice->total_amount),
            'currency' => '₼',
            'contract_number' => self::text($contractNumber),
            'issue_date' => self::dateValue($invoice->issue_date),
            'due_date' => self::dateValue($invoice->due_date),
            'period_start' => self::dateValue($invoice->period_start),
            'period_end' => self::dateValue($invoice->period_end),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $payment, ?Invoice $invoice = null): array
    {
        $invoiceNumber = $invoice?->invoice_number;
        if ($invoiceNumber === null && $payment->relationLoaded('invoice')) {
            $invoiceNumber = $payment->invoice?->invoice_number;
        }

        return array_filter([
            'amount_minor' => self::minorAmount($payment->getRawOriginal('amount')),
            'currency' => '₼',
            'invoice_number' => self::text($invoiceNumber),
            'payment_method' => self::text($payment->payment_method),
            'reason' => self::text($payment->cancel_reason),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function creditApplied(Invoice $invoice, int $amountMinor): array
    {
        return array_filter([
            'amount_minor' => $amountMinor,
            'currency' => '₼',
            'invoice_number' => self::text($invoice->invoice_number),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public static function companyFor(Contract $contract): Company
    {
        if ($contract->relationLoaded('company') && $contract->company instanceof Company) {
            return $contract->company;
        }

        return (new Company)->forceFill(['id' => $contract->company_id]);
    }

    public static function companyForInvoice(Invoice $invoice): Company
    {
        return (new Company)->forceFill(['id' => $invoice->company_id]);
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

    private static function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return self::text($value);
    }
}
