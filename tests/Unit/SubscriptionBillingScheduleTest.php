<?php

namespace Tests\Unit;

use App\Enums\CustomIntervalUnit;
use App\Services\SubscriptionBillingSchedule;
use App\ValueObjects\BillingInterval;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionBillingScheduleTest extends TestCase
{
    private SubscriptionBillingSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new SubscriptionBillingSchedule;
    }

    #[DataProvider('singleStepProvider')]
    public function test_calculates_anchored_intervals(
        string $current,
        string $anchor,
        int $value,
        CustomIntervalUnit $unit,
        string $expected,
    ): void {
        $actual = $this->schedule->nextOccurrenceStart(
            CarbonImmutable::parse($current),
            CarbonImmutable::parse($anchor),
            new BillingInterval($value, $unit),
        );

        $this->assertSame($expected, $actual->toDateString());
    }

    public static function singleStepProvider(): array
    {
        return [
            'monthly normal' => ['2026-01-15', '2026-01-15', 1, CustomIntervalUnit::Month, '2026-02-15'],
            'january 31 clamp' => ['2026-01-31', '2026-01-31', 1, CustomIntervalUnit::Month, '2026-02-28'],
            'january 31 rebound' => ['2026-02-28', '2026-01-31', 1, CustomIntervalUnit::Month, '2026-03-31'],
            'january 30 clamp' => ['2026-01-30', '2026-01-30', 1, CustomIntervalUnit::Month, '2026-02-28'],
            'january 30 rebound' => ['2026-02-28', '2026-01-30', 1, CustomIntervalUnit::Month, '2026-03-30'],
            'annual leap clamp' => ['2024-02-29', '2024-02-29', 1, CustomIntervalUnit::Year, '2025-02-28'],
            'annual leap restoration' => ['2027-02-28', '2024-02-29', 1, CustomIntervalUnit::Year, '2028-02-29'],
            'quarterly eom' => ['2026-08-31', '2026-08-31', 3, CustomIntervalUnit::Month, '2026-11-30'],
            'quarterly eom chain' => ['2027-02-28', '2026-08-31', 3, CustomIntervalUnit::Month, '2027-05-31'],
            'semiannual' => ['2026-04-30', '2026-04-30', 6, CustomIntervalUnit::Month, '2026-10-31'],
            'custom 45 days' => ['2026-01-01', '2026-01-01', 45, CustomIntervalUnit::Day, '2026-02-15'],
            'custom two months eom' => ['2026-01-31', '2026-01-31', 2, CustomIntervalUnit::Month, '2026-03-31'],
            'custom year leap' => ['2024-02-29', '2024-02-29', 1, CustomIntervalUnit::Year, '2025-02-28'],
        ];
    }

    public function test_period_end_is_day_before_next_start(): void
    {
        $start = CarbonImmutable::parse('2026-01-31');
        $interval = new BillingInterval(1, CustomIntervalUnit::Month);

        $this->assertSame(
            '2026-02-27',
            $this->schedule->periodEnd($start, $start, $interval)->toDateString(),
        );
    }

    public function test_leap_day_annual_chain_restores_anchor_in_2028(): void
    {
        $anchor = CarbonImmutable::parse('2024-02-29');
        $current = $anchor;
        $interval = new BillingInterval(1, CustomIntervalUnit::Year);

        foreach (['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'] as $expected) {
            $current = $this->schedule->nextOccurrenceStart($current, $anchor, $interval);
            $this->assertSame($expected, $current->toDateString());
        }
    }

    public function test_quarterly_end_of_month_chain_keeps_end_of_month(): void
    {
        $anchor = CarbonImmutable::parse('2026-08-31');
        $current = $anchor;
        $interval = new BillingInterval(3, CustomIntervalUnit::Month);

        foreach (['2026-11-30', '2027-02-28', '2027-05-31'] as $expected) {
            $current = $this->schedule->nextOccurrenceStart($current, $anchor, $interval);
            $this->assertSame($expected, $current->toDateString());
        }
    }

    public function test_occurrence_key_is_deterministic_and_sensitive_to_identity(): void
    {
        $start = CarbonImmutable::parse('2026-01-01');
        $end = CarbonImmutable::parse('2026-01-31');
        $key = $this->schedule->occurrenceKey(10, $start, $end);

        $this->assertSame(64, strlen($key));
        $this->assertSame($key, $this->schedule->occurrenceKey(10, $start, $end));
        $this->assertNotSame($key, $this->schedule->occurrenceKey(11, $start, $end));
    }

    public function test_calculation_does_not_mutate_inputs(): void
    {
        $current = CarbonImmutable::parse('2026-01-31');
        $anchor = CarbonImmutable::parse('2026-01-31');

        $this->schedule->periodEnd(
            $current,
            $anchor,
            new BillingInterval(1, CustomIntervalUnit::Month),
        );

        $this->assertSame('2026-01-31', $current->toDateString());
        $this->assertSame('2026-01-31', $anchor->toDateString());
    }
}
