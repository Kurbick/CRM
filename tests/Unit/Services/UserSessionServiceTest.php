<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_driver_performs_no_database_work(): void
    {
        config(['session.driver' => 'array']);
        (new UserSessionService())->deleteOtherSessions(User::factory()->make(['id' => 99]), 'current');
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_database_driver_deletes_only_other_sessions_for_user(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([['current', $user->id], ['old', $user->id], ['foreign', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        (new UserSessionService())->deleteOtherSessions($user, 'current');

        $this->assertDatabaseHas('sessions', ['id' => 'current']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
    }
}
