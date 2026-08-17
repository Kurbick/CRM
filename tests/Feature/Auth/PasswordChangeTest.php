<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_current_password_has_russian_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('user-password.update'), [
                'password' => 'Strong!Password12',
                'password_confirmation' => 'Strong!Password12',
            ])->assertSessionHasErrorsIn('updatePassword', [
                'current_password' => 'Введите текущий пароль.',
            ]);
    }

    public function test_wrong_current_password_has_russian_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('user-password.update'), [
                'current_password' => 'wrong',
                'password' => 'Strong!Password12',
                'password_confirmation' => 'Strong!Password12',
            ])->assertSessionHasErrorsIn('updatePassword', [
                'current_password' => 'Текущий пароль указан неверно.',
            ]);
    }

    public function test_validation_message_is_rendered_under_its_field(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('password.change'))->put(route('user-password.update'), [
            'current_password' => 'wrong',
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertRedirect(route('password.change'));

        $this->get(route('password.change'))->assertOk()
            ->assertSee('Текущий пароль указан неверно.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="current_password-error"', false);
    }

    public static function invalidPasswordProvider(): array
    {
        return [
            'short' => ['Short1!', 'Пароль должен содержать не менее 12 символов.'],
            'without uppercase' => ['lowercase!password12', 'Пароль должен содержать хотя бы одну заглавную и одну строчную букву.'],
            'without lowercase' => ['UPPERCASE!PASSWORD12', 'Пароль должен содержать хотя бы одну заглавную и одну строчную букву.'],
            'without number' => ['NoDigits!Password', 'Пароль должен содержать хотя бы одну цифру.'],
            'without symbol' => ['NoSymbolsPassword12', 'Пароль должен содержать хотя бы один специальный символ.'],
        ];
    }

    #[DataProvider('invalidPasswordProvider')]
    public function test_password_policy_has_precise_russian_message(string $password, string $message): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasErrorsIn('updatePassword', ['password' => $message]);
    }

    public function test_password_confirmation_has_russian_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Strong!Password12',
                'password_confirmation' => 'Different!Password12',
            ])->assertSessionHasErrorsIn('updatePassword', [
                'password_confirmation' => 'Пароли не совпадают.',
            ]);
    }

    public function test_reused_password_has_russian_message(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Current!Password12')]);

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'Current!Password12',
                'password' => 'Current!Password12',
                'password_confirmation' => 'Current!Password12',
            ])->assertSessionHasErrorsIn('updatePassword', [
                'password' => 'Новый пароль должен отличаться от текущего.',
            ]);
    }

    public function test_current_confirmation_strength_and_reuse_are_validated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Current!Password12'),
        ]);
        $this->actingAs($user);

        $this->put(route('user-password.update'), [
            'current_password' => 'wrong',
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->put(route('user-password.update'), [
            'current_password' => 'Current!Password12',
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->put(route('user-password.update'), [
            'current_password' => 'Current!Password12',
            'password' => 'Current!Password12',
            'password_confirmation' => 'Current!Password12',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_forced_password_change_does_not_require_current_password(): void
    {
        $user = User::factory()->requiringPasswordChange()->create([
            'password' => Hash::make('Temporary!Password12'),
        ]);

        $this->actingAs($user)
            ->get(route('password.change'))
            ->assertOk()
            ->assertSee('Установите новый пароль')
            ->assertDontSee('name="current_password"', false);

        $this->put(route('user-password.update'), [
            'password' => 'New!Password123',
            'password_confirmation' => 'New!Password123',
        ])->assertRedirect(route('home'));

        $this->assertFalse($user->fresh()->mustChangePassword());
    }

    public function test_strong_password_clears_temporary_flag_and_keeps_current_session(): void
    {
        $user = User::factory()->requiringPasswordChange()->create();
        $this->actingAs($user);
        session()->put('url.intended', route('companies.index'));
        $before = session()->getId();

        $this->put(route('user-password.update'), [
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertRedirect(route('home'))->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('Strong!Password12', $user->password));
        $this->assertFalse($user->mustChangePassword());
        $this->assertNotNull($user->password_changed_at);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session()->get('url.intended'));
        $this->get(route('home'))->assertOk();
    }

    public function test_voluntary_password_change_uses_authorized_landing_redirect(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertRedirect(route('home'))->assertSessionHas('success');

        $this->assertFalse($user->fresh()->mustChangePassword());
    }

    #[DataProvider('intendedUrlProvider')]
    public function test_password_update_always_discards_intended_url(
        string $intended,
        array $permissions,
        string $expectedRoute
    ): void {
        app(AccessControlSynchronizer::class)->sync();
        $user = User::factory()->requiringPasswordChange()->create();
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $this->actingAs($user);
        session()->put('url.intended', $intended);

        $response = $this->put(route('user-password.update'), [
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertRedirect(route($expectedRoute))->assertSessionHas('success');

        $this->assertNull(session()->get('url.intended'));
        $this->assertFalse($user->fresh()->mustChangePassword());
        $this->assertStringStartsWith(config('app.url'), (string) $response->headers->get('Location'));
        $this->get((string) $response->headers->get('Location'))->assertOk();
    }

    public function test_password_change_deletes_only_other_database_sessions(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        $user = User::factory()->requiringPasswordChange()->create();
        $other = User::factory()->create();

        foreach ([['old-own-session', $user->id], ['other-user-session', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->actingAs($user)->put(route('user-password.update'), [
            'password' => 'Strong!Password12',
            'password_confirmation' => 'Strong!Password12',
        ])->assertRedirect(route('home'));

        $this->assertDatabaseMissing('sessions', ['id' => 'old-own-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);
        $this->assertAuthenticatedAs($user);
    }

    public static function intendedUrlProvider(): array
    {
        return [
            'forbidden internal' => ['/companies', [], 'home'],
            'authorized internal still follows priority' => [
                '/invoices', [PermissionName::CompaniesView->value, PermissionName::InvoicesView->value], 'companies.index',
            ],
            'external https' => ['https://evil.example/redirect', [PermissionName::DashboardView->value], 'dashboard'],
            'protocol relative' => ['//evil.example/redirect', [PermissionName::CompaniesView->value], 'companies.index'],
            'encoded protocol relative' => ['/%2F%2Fevil.example/redirect', [], 'home'],
            'javascript scheme' => ['javascript:alert(1)', [], 'home'],
            'malformed' => ['::not a valid URL::', [], 'home'],
            'internal query string' => [
                '/companies?tab=payments&secret=marker', [PermissionName::ContractsView->value], 'contracts.index',
            ],
        ];
    }
}
