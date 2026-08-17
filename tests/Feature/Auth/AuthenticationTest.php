<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_login_and_active_user_can_login(): void
    {
        $user = User::factory()->create(['email' => 'USER@EXAMPLE.COM']);
        $updatedAt = $user->updated_at;

        $this->get(route('login'))->assertOk()->assertSee('>Вход</h1>', false);
        $loginAt = CarbonImmutable::create(2031, 2, 3, 8, 9, 10, 'UTC');
        Carbon::setTestNow($loginAt);
        try {
            $this->post(route('login.store'), [
                'email' => 'user@example.com',
                'password' => 'password',
            ])->assertRedirect(route('home'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue($updatedAt->equalTo($user->fresh()->updated_at));
        $this->assertSame(
            $loginAt->getTimestamp(),
            (int) DB::selectOne('SELECT UNIX_TIMESTAMP(last_login_at) AS epoch FROM users WHERE id = ?', [$user->getKey()])->epoch,
        );
    }

    public function test_unknown_bad_password_and_inactive_user_share_generic_failure(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        foreach ([
            ['email' => 'missing@example.com', 'password' => 'wrong'],
            ['email' => $active->email, 'password' => 'wrong'],
            ['email' => $inactive->email, 'password' => 'password'],
        ] as $credentials) {
            $this->post(route('login.store'), $credentials)
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->assertNull($active->fresh()->last_login_at);
        $this->assertNull($inactive->fresh()->last_login_at);
    }

    public function test_login_regenerates_session_and_supports_remember(): void
    {
        $user = User::factory()->create();
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('home'));

        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    #[DataProvider('intendedUrlProvider')]
    public function test_successful_login_always_discards_intended_url(
        string $intended,
        array $permissions,
        string $expectedRoute
    ): void {
        app(AccessControlSynchronizer::class)->sync();
        $user = User::factory()->create();
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        $response = $this->withSession(['url.intended' => $intended])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route($expectedRoute));

        $this->assertNull(session()->get('url.intended'));
        $this->assertStringStartsWith(config('app.url'), (string) $response->headers->get('Location'));
        $this->get((string) $response->headers->get('Location'))->assertOk();
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => 'limit@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => 'limit@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_logout_is_post_only_and_ends_authentication(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/logout')->assertMethodNotAllowed();
    }

    public static function intendedUrlProvider(): array
    {
        return [
            'forbidden internal' => [
                '/companies', [], 'home',
            ],
            'authorized internal still follows priority' => [
                '/invoices', [PermissionName::CompaniesView->value, PermissionName::InvoicesView->value], 'companies.index',
            ],
            'external https' => [
                'https://evil.example/redirect', [PermissionName::DashboardView->value], 'dashboard',
            ],
            'protocol relative' => [
                '//evil.example/redirect', [PermissionName::CompaniesView->value], 'companies.index',
            ],
            'encoded protocol relative' => [
                '/%2F%2Fevil.example/redirect', [], 'home',
            ],
            'javascript scheme' => [
                'javascript:alert(1)', [], 'home',
            ],
            'malformed' => [
                '::not a valid URL::', [], 'home',
            ],
            'internal query string' => [
                '/companies?tab=payments&secret=marker', [PermissionName::ContractsView->value], 'contracts.index',
            ],
        ];
    }
}
