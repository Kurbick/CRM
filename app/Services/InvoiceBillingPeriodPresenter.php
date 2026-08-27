<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class InvoiceBillingPeriodPresenter
{
    /**
     * @param  iterable<\App\Models\InvoiceLine>  $lines
     * @return array{kind: 'none'|'continuous'|'disjoint', label: string, period_count: int, count_label: ?string}
     */
    public function present(Invoice $invoice, iterable $lines): array
    {
        $periods = collect($lines)
            ->map(fn ($line): ?array => $this->period($line->period_start ?? null, $line->period_end ?? null))
            ->filter()
            ->unique(fn (array $period): string => $period['start']->toDateString().'|'.$period['end']->toDateString())
            ->sortBy([
                ['start', 'asc'],
                ['end', 'asc'],
            ])
            ->values();

        if ($periods->isEmpty()) {
            $legacyPeriod = $this->period($invoice->period_start, $invoice->period_end);

            if ($legacyPeriod === null) {
                return $this->none();
            }

            return $this->continuous(collect([$legacyPeriod]));
        }

        $coveredEnd = null;
        foreach ($periods as $period) {
            if ($coveredEnd !== null && $period['start']->gt($coveredEnd->addDay())) {
                return [
                    'kind' => 'disjoint',
                    'label' => __('invoices.billing.multiple'),
                    'period_count' => $periods->count(),
                    'count_label' => null,
                ];
            }

            if ($coveredEnd === null || $period['end']->gt($coveredEnd)) {
                $coveredEnd = $period['end'];
            }
        }

        return $this->continuous($periods);
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    private function period(mixed $start, mixed $end): ?array
    {
        $start = $this->calendarDate($start);
        $end = $this->calendarDate($end);

        if ($start === null || $end === null || $end->lt($start)) {
            return null;
        }

        return compact('start', 'end');
    }

    private function calendarDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value->format('Y-m-d'));
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date->format('Y-m-d') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param Collection<int, array{start: CarbonImmutable, end: CarbonImmutable}> $periods */
    private function continuous(Collection $periods): array
    {
        $count = $periods->count();

        return [
            'kind' => 'continuous',
            'label' => $periods->first()['start']->format('d/m/Y').' — '.$periods->last()['end']->format('d/m/Y'),
            'period_count' => $count,
            'count_label' => $count > 1 ? $count.' '.$this->periodWord($count) : null,
        ];
    }

    /** @return array{kind: 'none', label: '—', period_count: 0, count_label: null} */
    private function none(): array
    {
        return [
            'kind' => 'none',
            'label' => '—',
            'period_count' => 0,
            'count_label' => null,
        ];
    }

    private function periodWord(int $count): string
    {
        $lastTwo = $count % 100;
        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return __('invoices.billing.period_many');
        }

        return match ($count % 10) {
            1 => __('invoices.billing.period_one'),
            2, 3, 4 => __('invoices.billing.period_few'),
            default => __('invoices.billing.period_many'),
        };
    }
}
