<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_uses_accessible_password_toggle_without_password_value(): void
    {
        $response = $this->get(route('login'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('name="password"', false)
            ->assertSee('type="password"', false)
            ->assertSee('type="button"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee("visible ? 'text' : 'password'", false)
            ->assertSee("visible ? 'Скрыть пароль' : 'Показать пароль'", false);
        $this->assertStringNotContainsString('name="password" value=', $html);
        $this->assertStringNotContainsString('>CR</span>', $html);
        $this->assertStringNotContainsString('>CRM</span>', $html);
        $this->assertStringNotContainsString('mb-6 flex items-center justify-center', $html);
        $this->assertStringContainsString('<div class="w-full max-w-md">'.PHP_EOL.'            <section', $html);
        $this->assertStringContainsString('flex justify-center', $html);
        $this->assertStringNotContainsString('min-w-36 w-full', $html);
    }

    public function test_change_password_has_three_independent_toggles_and_requirements(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('password.change'))->assertOk();
        $html = $response->getContent();

        foreach ([
            'name="current_password"',
            'name="password"',
            'name="password_confirmation"',
            'autocomplete="current-password"',
            'autocomplete="new-password"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $this->assertSame(3, substr_count($html, 'x-data="{ visible: false }"'));
        $this->assertSame(3, substr_count($html, "visible ? 'Скрыть пароль' : 'Показать пароль'"));
        $this->assertStringContainsString('Не менее 12 символов, включая заглавную и строчную буквы, цифру и специальный символ.', $html);
        $this->assertStringNotContainsString('name="password" value=', $html);
        $this->assertStringNotContainsString('name="password_confirmation" value=', $html);
    }

    public function test_authenticated_layout_contains_settings_menu_and_post_logout_only(): void
    {
        $user = User::factory()->create(['name' => 'UI User']);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('UI User')
            ->assertSee('aria-label="Настройки"', false)
            ->assertSee(route('password.change'), false)
            ->assertSee('Сменить пароль')
            ->assertSee('x-on:click.outside="open = false"', false)
            ->assertSee('x-on:keydown.escape.window="open = false"', false);
        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));
        $this->assertStringContainsString('method="POST" action="'.route('logout').'"', $html);
        $this->assertStringNotContainsString('Пользователи', $html);
        $this->assertStringNotContainsString('Группы', $html);
        $this->assertStringNotContainsString('Права доступа', $html);

        $guestHtml = $this->get(route('login'))->getContent();
        $this->assertStringNotContainsString('aria-label="Настройки"', $guestHtml);
    }
}
