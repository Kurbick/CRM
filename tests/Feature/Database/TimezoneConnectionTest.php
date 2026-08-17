<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimezoneConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_application_session_uses_utc_and_now_matches_utc_timestamp(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                @@session.time_zone AS session_timezone,
                NOW(6) AS now_value,
                UTC_TIMESTAMP(6) AS utc_value,
                TIMESTAMPDIFF(MICROSECOND, NOW(6), UTC_TIMESTAMP(6)) AS delta_microseconds
        SQL);

        $this->assertContains((string) $row->session_timezone, ['+00:00', 'UTC']);
        $this->assertLessThan(1000, abs((int) $row->delta_microseconds));
        $this->assertSame(
            Carbon::parse($row->now_value, 'UTC')->format('Y-m-d H:i:s'),
            Carbon::parse($row->utc_value, 'UTC')->format('Y-m-d H:i:s'),
        );
    }

    public function test_timestamp_round_trip_preserves_the_utc_instant(): void
    {
        $input = CarbonImmutable::create(2031, 2, 3, 8, 9, 10, 'UTC');
        DB::beginTransaction();
        Carbon::setTestNow($input);

        try {
            $user = User::factory()->make([
                'email' => 'tz-round-trip-'.str()->random(12).'@example.test',
                'last_login_at' => $input,
            ]);
            $user->saveQuietly();

            $sql = DB::selectOne(
                'SELECT UNIX_TIMESTAMP(last_login_at) AS last_login_epoch, UNIX_TIMESTAMP(created_at) AS created_epoch FROM users WHERE id = ?',
                [$user->getKey()],
            );
            $hydrated = User::query()->findOrFail($user->getKey());

            $this->assertSame($input->getTimestamp(), (int) $sql->last_login_epoch);
            $this->assertSame($input->getTimestamp(), $hydrated->last_login_at?->getTimestamp());
            $this->assertSame($input->getTimestamp(), (int) $sql->created_epoch);
            $this->assertSame($input->getTimestamp(), $hydrated->created_at?->getTimestamp());
        } finally {
            Carbon::setTestNow();
            DB::rollBack();
        }
    }
}
