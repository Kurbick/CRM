<?php

namespace Tests\Unit\Support;

use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DisplayDateTimeTest extends TestCase
{
    public function test_utc_instant_is_formatted_in_configured_display_timezone_without_mutating_input(): void
    {
        $value = Carbon::create(2031, 2, 3, 8, 9, 10, 'UTC');
        $original = $value->toIso8601String();

        $this->assertSame('03.02.2031 12:09:10', app(DisplayDateTime::class)->format($value, 'd.m.Y H:i:s'));
        $this->assertSame('UTC', $value->getTimezone()->getName());
        $this->assertSame($original, $value->toIso8601String());
    }

    public function test_null_is_formatted_as_null_and_display_timezone_is_configurable(): void
    {
        $formatter = app(DisplayDateTime::class);

        $this->assertNull($formatter->format(null, 'd.m.Y H:i'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Baku', config('app.display_timezone'));

        config(['app.display_timezone' => 'Europe/Berlin']);

        $this->assertSame(
            '03.02.2031 09:09',
            $formatter->format(CarbonImmutable::create(2031, 2, 3, 8, 9, 10, 'UTC'), 'd.m.Y H:i'),
        );
        $this->assertSame('UTC', config('app.timezone'));
    }
}
