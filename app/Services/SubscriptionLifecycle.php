<?php

namespace App\Services;

use App\Enums\CustomIntervalUnit;
use App\Enums\SubscriptionBillingPeriod;
use App\Models\Subscription;
use App\ValueObjects\BillingInterval;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SubscriptionLifecycle
{
    private const SCHEDULE_FIELDS = [
        'start_date',
        'billing_period',
        'custom_interval_value',
        'custom_interval_unit',
    ];

    public function normalizeInterval(array $attributes): array
    {
        $rawPeriod = $attributes['billing_period'] ?? null;
        $period = $rawPeriod instanceof SubscriptionBillingPeriod
            ? $rawPeriod
            : SubscriptionBillingPeriod::tryFrom(is_string($rawPeriod) ? $rawPeriod : '');

        if ($period === null) {
            throw ValidationException::withMessages([
                'billing_period' => 'Укажите допустимый период биллинга.',
            ]);
        }

        $attributes['billing_period'] = $period->value;

        if ($period !== SubscriptionBillingPeriod::Custom) {
            $attributes['custom_interval_value'] = null;
            $attributes['custom_interval_unit'] = null;

            return $attributes;
        }

        $errors = [];
        if (! array_key_exists('custom_interval_value', $attributes)
            || $attributes['custom_interval_value'] === null
            || $attributes['custom_interval_value'] === '') {
            $errors['custom_interval_value'] = ['Укажите значение своего интервала.'];
        }
        if (! array_key_exists('custom_interval_unit', $attributes)
            || $attributes['custom_interval_unit'] === null
            || $attributes['custom_interval_unit'] === '') {
            $errors['custom_interval_unit'] = ['Укажите единицу своего интервала.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $value = filter_var($attributes['custom_interval_value'], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw ValidationException::withMessages([
                'custom_interval_value' => 'Значение своего интервала должно быть целым числом от 1 до 3650.',
            ]);
        }

        $rawUnit = $attributes['custom_interval_unit'];
        $unit = $rawUnit instanceof CustomIntervalUnit
            ? $rawUnit
            : (is_string($rawUnit) ? CustomIntervalUnit::tryFrom($rawUnit) : null);
        if ($unit === null) {
            throw ValidationException::withMessages([
                'custom_interval_unit' => 'Укажите допустимую единицу своего интервала.',
            ]);
        }

        try {
            $interval = new BillingInterval($value, $unit);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'custom_interval_value' => 'Значение своего интервала должно быть целым числом от 1 до 3650.',
            ]);
        }

        $attributes['custom_interval_value'] = $interval->value;
        $attributes['custom_interval_unit'] = $interval->unit->value;

        return $attributes;
    }

    public function applyUpdate(Subscription $subscription, array $attributes): void
    {
        [$attributes, $effectiveSchedule] = $this->effectiveState($subscription, $attributes);
        $scheduleChanged = $this->scheduleChanged($subscription, $effectiveSchedule);

        if ($scheduleChanged && $subscription->invoiceLines()->exists()) {
            throw ValidationException::withMessages([
                'start_date' => 'График нельзя изменить после добавления подписки в счёт.',
            ]);
        }

        $previousStatus = (string) $subscription->status;
        $nextBillingDate = $scheduleChanged
            ? CarbonImmutable::parse($effectiveSchedule['start_date'])->startOfDay()
            : CarbonImmutable::parse($subscription->next_billing_date)->startOfDay();

        $subscription->fill($attributes);

        if (
            $previousStatus !== 'active'
            && (string) $subscription->status === 'active'
            && $nextBillingDate->isBefore(CarbonImmutable::today())
        ) {
            $nextBillingDate = CarbonImmutable::today();
        }

        $subscription->next_billing_date = $nextBillingDate->toDateString();
        $subscription->save();
    }

    private function effectiveState(Subscription $subscription, array $attributes): array
    {
        $hasCustomValue = array_key_exists('custom_interval_value', $attributes);
        $hasCustomUnit = array_key_exists('custom_interval_unit', $attributes);
        $effectiveSchedule = [
            'start_date' => array_key_exists('start_date', $attributes)
                ? $attributes['start_date']
                : $subscription->start_date,
            'billing_period' => array_key_exists('billing_period', $attributes)
                ? $attributes['billing_period']
                : $subscription->billing_period,
            'custom_interval_value' => array_key_exists('custom_interval_value', $attributes)
                ? $attributes['custom_interval_value']
                : $subscription->custom_interval_value,
            'custom_interval_unit' => array_key_exists('custom_interval_unit', $attributes)
                ? $attributes['custom_interval_unit']
                : $subscription->custom_interval_unit,
        ];

        if ($effectiveSchedule['billing_period'] !== 'custom') {
            $effectiveSchedule['custom_interval_value'] = null;
            $effectiveSchedule['custom_interval_unit'] = null;
        } elseif ($hasCustomValue xor $hasCustomUnit) {
            throw ValidationException::withMessages([
                $hasCustomValue ? 'custom_interval_unit' : 'custom_interval_value' => 'Значение и единица своего интервала должны передаваться вместе.',
            ]);
        } elseif (
            $effectiveSchedule['custom_interval_value'] === null
            || $effectiveSchedule['custom_interval_unit'] === null
        ) {
            throw ValidationException::withMessages([
                'custom_interval_value' => 'Укажите полный свой интервал.',
                'custom_interval_unit' => 'Укажите полный свой интервал.',
            ]);
        }

        $effectiveSchedule = $this->normalizeInterval($effectiveSchedule);

        $attributes['custom_interval_value'] = $effectiveSchedule['custom_interval_value'];
        $attributes['custom_interval_unit'] = $effectiveSchedule['custom_interval_unit'];

        return [$attributes, $effectiveSchedule];
    }

    private function scheduleChanged(Subscription $subscription, array $effectiveSchedule): bool
    {
        foreach (self::SCHEDULE_FIELDS as $field) {

            $current = $subscription->getRawOriginal($field);
            $incoming = $effectiveSchedule[$field];

            if ($field === 'start_date') {
                $current = $current ? CarbonImmutable::parse($current)->toDateString() : null;
                $incoming = $incoming ? CarbonImmutable::parse($incoming)->toDateString() : null;
            }

            if ((string) $current !== (string) $incoming) {
                return true;
            }
        }

        return false;
    }
}
