<?php

namespace App\Services;

use App\Enums\CustomIntervalUnit;
use App\Enums\SubscriptionBillingPeriod;
use App\Models\Subscription;
use App\ValueObjects\BillingInterval;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SubscriptionBillingSchedule
{
    public function intervalFor(Subscription $subscription): BillingInterval
    {
        $period = SubscriptionBillingPeriod::tryFrom((string) $subscription->billing_period);

        return match ($period) {
            SubscriptionBillingPeriod::Monthly => new BillingInterval(1, CustomIntervalUnit::Month),
            SubscriptionBillingPeriod::Quarterly => new BillingInterval(3, CustomIntervalUnit::Month),
            SubscriptionBillingPeriod::Semiannual => new BillingInterval(6, CustomIntervalUnit::Month),
            SubscriptionBillingPeriod::Annual => new BillingInterval(1, CustomIntervalUnit::Year),
            SubscriptionBillingPeriod::Custom => $this->customIntervalFor($subscription),
            null => throw new InvalidArgumentException('Unknown subscription billing period.'),
        };
    }

    public function nextOccurrenceStart(
        CarbonImmutable $currentStart,
        CarbonImmutable $anchorDate,
        BillingInterval $interval,
    ): CarbonImmutable {
        if ($interval->unit === CustomIntervalUnit::Day) {
            return $currentStart->addDays($interval->value);
        }

        $months = $interval->unit === CustomIntervalUnit::Year
            ? $interval->value * 12
            : $interval->value;
        $targetMonth = $currentStart->startOfMonth()->addMonths($months);

        if ($anchorDate->isLastOfMonth()) {
            return $targetMonth->endOfMonth()->startOfDay();
        }

        return $targetMonth->day(min($anchorDate->day, $targetMonth->daysInMonth));
    }

    public function periodEnd(
        CarbonImmutable $periodStart,
        CarbonImmutable $anchorDate,
        BillingInterval $interval,
    ): CarbonImmutable {
        return $this->nextOccurrenceStart($periodStart, $anchorDate, $interval)->subDay();
    }

    public function occurrenceKey(
        int $subscriptionId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): string {
        return hash('sha256', implode('|', [
            $subscriptionId,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
        ]));
    }

    private function customIntervalFor(Subscription $subscription): BillingInterval
    {
        $value = filter_var($subscription->custom_interval_value, FILTER_VALIDATE_INT);
        $unit = CustomIntervalUnit::tryFrom((string) $subscription->custom_interval_unit);

        if ($value === false || $unit === null) {
            throw new InvalidArgumentException('Custom subscription interval is incomplete.');
        }

        return new BillingInterval($value, $unit);
    }
}
