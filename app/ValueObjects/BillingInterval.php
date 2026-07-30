<?php

namespace App\ValueObjects;

use App\Enums\CustomIntervalUnit;
use InvalidArgumentException;

final readonly class BillingInterval
{
    public function __construct(
        public int $value,
        public CustomIntervalUnit $unit,
    ) {
        if ($value < 1 || $value > 3650) {
            throw new InvalidArgumentException('Billing interval value must be between 1 and 3650.');
        }
    }
}
