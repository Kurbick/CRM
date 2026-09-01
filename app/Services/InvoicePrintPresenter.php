<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class InvoicePrintPresenter
{
    /**
     * @return list<array{description: string, amount: mixed}>
     */
    public function lines(Invoice $invoice): array
    {
        $contractNumber = $this->text(
            $invoice->contract?->contract_number ?: $invoice->contract_reference
        );

        return $invoice->lines
            ->sortBy('id')
            ->values()
            ->map(fn (InvoiceLine $line): array => [
                'description' => $this->description($invoice, $line, $contractNumber),
                'amount' => $line->amount,
            ])
            ->all();
    }

    private function description(Invoice $invoice, InvoiceLine $line, ?string $contractNumber): string
    {
        $description = $this->text($line->description) ?? __('invoices.print.not_specified');
        $period = $this->periodLabel($line->period_start, $line->period_end);

        if ($period === null && $line->subscription_id !== null) {
            $period = $this->periodLabel($invoice->period_start, $invoice->period_end);
        }

        $translation = match (true) {
            $contractNumber !== null && $period !== null => 'invoices.print.line.contract_period',
            $contractNumber !== null => 'invoices.print.line.contract',
            $period !== null => 'invoices.print.line.period',
            default => 'invoices.print.line.plain',
        };

        return __($translation, [
            'contract' => $contractNumber,
            'description' => $description,
            'period' => $period,
        ]);
    }

    private function periodLabel(mixed $start, mixed $end): ?string
    {
        $start = $this->date($start);
        $end = $this->date($end);

        if ($start === null || $end === null || $end->lt($start)) {
            return null;
        }

        if ($start->day === 1
            && $end->isLastOfMonth()
            && $start->isSameMonth($end)) {
            return __('invoices.print.months.'.$start->month).' '.$start->year;
        }

        return __('invoices.print.period_range', [
            'start' => $start->format('d/m/Y'),
            'end' => $end->format('d/m/Y'),
        ]);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value->format('Y-m-d'));
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
