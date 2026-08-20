<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceBillingPeriodPresenter;
use Tests\TestCase;

class InvoiceBillingPeriodPresenterTest extends TestCase
{
    public function test_it_presents_one_line_period_without_timezone_shift(): void
    {
        $period = $this->present([['2026-06-01', '2026-06-30']]);

        $this->assertSame('continuous', $period['kind']);
        $this->assertSame('01/06/2026 — 30/06/2026', $period['label']);
        $this->assertSame(1, $period['period_count']);
        $this->assertNull($period['count_label']);
    }

    public function test_it_merges_consecutive_and_overlapping_ranges_and_counts_distinct_pairs(): void
    {
        $period = $this->present([
            ['2026-06-01', '2026-06-30'],
            ['2026-07-01', '2026-07-31'],
            ['2026-08-01', '2026-08-31'],
            ['2026-09-01', '2026-09-30'],
            ['2026-10-01', '2026-10-31'],
            ['2026-06-01', '2026-06-30'],
            ['2026-10-15', '2026-10-31'],
        ]);

        $this->assertSame('continuous', $period['kind']);
        $this->assertSame('01/06/2026 — 31/10/2026', $period['label']);
        $this->assertSame(6, $period['period_count']);
        $this->assertSame('6 расчётных периодов', $period['count_label']);
    }

    public function test_five_consecutive_periods_have_the_correct_count_label(): void
    {
        $period = $this->present([
            ['2026-06-01', '2026-06-30'],
            ['2026-07-01', '2026-07-31'],
            ['2026-08-01', '2026-08-31'],
            ['2026-09-01', '2026-09-30'],
            ['2026-10-01', '2026-10-31'],
        ]);

        $this->assertSame('01/06/2026 — 31/10/2026', $period['label']);
        $this->assertSame('5 расчётных периодов', $period['count_label']);
    }

    public function test_it_treats_adjacent_non_month_ranges_as_continuous(): void
    {
        $period = $this->present([
            ['2026-09-13', '2026-10-12'],
            ['2026-10-13', '2026-11-12'],
        ]);

        $this->assertSame('13/09/2026 — 12/11/2026', $period['label']);
        $this->assertSame('2 расчётных периода', $period['count_label']);
    }

    public function test_it_never_invents_a_range_over_a_real_gap(): void
    {
        $period = $this->present([
            ['2026-06-01', '2026-06-30'],
            ['2026-08-01', '2026-08-31'],
        ]);

        $this->assertSame('disjoint', $period['kind']);
        $this->assertSame('Несколько расчётных периодов', $period['label']);
        $this->assertNull($period['count_label']);
    }

    public function test_it_uses_complete_legacy_period_only_when_lines_have_no_usable_periods(): void
    {
        $invoice = new Invoice(['period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
        $presenter = new InvoiceBillingPeriodPresenter;

        $this->assertSame('01/01/2026 — 31/01/2026', $presenter->present($invoice, [new InvoiceLine])['label']);
        $this->assertSame(
            '01/02/2026 — 28/02/2026',
            $presenter->present($invoice, [$this->line('2026-02-01', '2026-02-28')])['label']
        );
    }

    public function test_it_handles_absent_and_partial_data_conservatively(): void
    {
        $presenter = new InvoiceBillingPeriodPresenter;
        $partialLegacy = new Invoice(['period_start' => '2026-01-01']);
        $partialLine = $this->line('2026-01-01', null);

        $period = $presenter->present($partialLegacy, [$partialLine]);

        $this->assertSame('none', $period['kind']);
        $this->assertSame('—', $period['label']);
    }

    private function present(array $ranges): array
    {
        $lines = array_map(fn (array $range): InvoiceLine => $this->line(...$range), $ranges);

        return (new InvoiceBillingPeriodPresenter)->present(new Invoice, $lines);
    }

    private function line(?string $start, ?string $end): InvoiceLine
    {
        return new InvoiceLine(['period_start' => $start, 'period_end' => $end]);
    }
}
