<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class DisplayDateTime
{
    public function format(?DateTimeInterface $value, string $format): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)
            ->setTimezone((string) config('app.display_timezone', 'Asia/Baku'))
            ->format($format);
    }
}
