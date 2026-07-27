<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_is_assigned_without_authentication_changes(): void
    {
        $user = User::factory()->requiringPasswordChange()->create(['email' => 'user@example.test']);
        $hash = $user->password;

        $this->artisan('app:create-admin')
            ->expectsQuestion('Email', ' USER@EXAMPLE.TEST ')
            ->expectsConfirmation('Назначить пользователю группу Administrator?', 'yes')
            ->expectsOutputToContain('Administrator назначен.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->hasRole('administrator'));
        $this->assertSame($hash, $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_inactive_existing_user_requires_activation_confirmation(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'inactive@example.test']);
        $this->artisan('app:create-admin')
            ->expectsQuestion('Email', 'inactive@example.test')
            ->expectsConfirmation('Назначить пользователю группу Administrator?', 'yes')
            ->expectsConfirmation('Пользователь неактивен. Активировать его?', 'no')
            ->assertFailed();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_new_user_is_created_atomically_with_strong_password(): void
    {
        $password = 'Strong-Password-123!';
        $this->artisan('app:create-admin')
            ->expectsQuestion('Email', 'NEW@EXAMPLE.TEST')
            ->expectsQuestion('Имя', 'Новый Администратор')
            ->expectsQuestion('Пароль', $password)
            ->expectsQuestion('Подтверждение пароля', $password)
            ->assertSuccessful();

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue($user->hasRole('administrator'));
        $this->assertDatabaseCount('model_has_permissions', 0);
    }

    public function test_weak_or_mismatched_password_does_not_create_user_or_leak_secret(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Email', 'weak@example.test')
            ->expectsQuestion('Имя', 'Weak')
            ->expectsQuestion('Пароль', 'weak')
            ->expectsQuestion('Подтверждение пароля', 'different')
            ->doesntExpectOutput('weak')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'weak@example.test']);
    }
}
