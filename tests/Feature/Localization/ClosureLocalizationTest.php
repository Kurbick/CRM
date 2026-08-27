<?php

namespace Tests\Feature\Localization;

use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ClosureLocalizationTest extends AuthorizationTestCase
{
    public function test_shell_and_home_are_localized_in_both_locales(): void
    {
        $this->actingAsPermissions([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
        ]);

        $ru = $this->withSession(['locale' => 'ru'])
            ->get(route('home'))
            ->assertOk()
            ->assertSeeText('Главная')
            ->assertSeeText('Доступные разделы')
            ->assertSeeText('Сменить пароль')
            ->assertSee('aria-label="Настройки"', false)
            ->assertSee('aria-label="Основная навигация"', false);

        $az = $this->withSession(['locale' => 'az'])
            ->get(route('home'))
            ->assertOk()
            ->assertSeeText('Əsas səhifə')
            ->assertSeeText('Əlçatan bölmələr')
            ->assertSeeText('Şifrəni dəyiş')
            ->assertSee('aria-label="Parametrlər"', false)
            ->assertSee('aria-label="Əsas naviqasiya"', false);

        $this->assertStringNotContainsString('navigation.shell', $ru->getContent());
        $this->assertStringNotContainsString('navigation.shell', $az->getContent());
    }

    public function test_auth_and_password_screens_use_approved_password_terminology(): void
    {
        $ruLogin = $this->withSession(['locale' => 'ru'])
            ->get(route('login'))
            ->assertOk()
            ->assertSeeText('Вход')
            ->assertSeeText('Введите данные внутренней учётной записи.')
            ->assertSeeText('Email')
            ->assertSeeText('Пароль')
            ->assertSeeText('Запомнить меня')
            ->assertSeeText('Войти');

        $azLogin = $this->withSession(['locale' => 'az'])
            ->get(route('login'))
            ->assertOk()
            ->assertSeeText('Giriş')
            ->assertSeeText('Daxili hesab məlumatlarını daxil edin.')
            ->assertSeeText('Email')
            ->assertSeeText('Şifrə')
            ->assertSeeText('Məni yadda saxla')
            ->assertSeeText('Daxil ol')
            ->assertDontSeeText('Вход')
            ->assertDontSeeText('Введите данные внутренней учётной записи.')
            ->assertDontSeeText('Запомнить меня')
            ->assertDontSeeText('Войти');

        $user = \App\Models\User::factory()->create();
        $azPassword = $this->withSession(['locale' => 'az'])
            ->actingAs($user)
            ->get(route('password.change'))
            ->assertOk()
            ->assertSeeText('Cari şifrə')
            ->assertSeeText('Yeni şifrə')
            ->assertSeeText('Şifrənin təsdiqi')
            ->assertSeeText('Şifrəni dəyiş');

        foreach ([$azLogin->getContent(), $azPassword->getContent()] as $html) {
            $this->assertStringNotContainsString('Parol', $html);
            $this->assertStringNotContainsString('Redakt', $html);
            $this->assertStringNotContainsString('predmet', mb_strtolower($html));
        }

        $this->assertStringContainsString('Пароль', $ruLogin->getContent());
    }

    public function test_guest_can_switch_login_locale_in_both_directions(): void
    {
        $ru = $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('RU')
            ->assertSeeText('AZ')
            ->assertSee('name="locale" value="ru"', false)
            ->assertSee('name="locale" value="az"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSeeText('Вход');

        $this->assertSame('ru', app()->getLocale());
        $this->assertSame('ru', session('locale'));

        $this->post(route('locale.update'), ['locale' => 'az'], [
            'Referer' => route('login'),
        ])->assertRedirect(route('login'));

        $az = $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Giriş')
            ->assertSeeText('Daxili hesab məlumatlarını daxil edin.')
            ->assertSeeText('Email')
            ->assertSeeText('Şifrə')
            ->assertSeeText('Məni yadda saxla')
            ->assertSeeText('Daxil ol')
            ->assertDontSeeText('Вход')
            ->assertSee('aria-pressed="true"', false);

        $this->assertSame('az', app()->getLocale());
        $this->assertSame('az', session('locale'));

        $this->post(route('locale.update'), ['locale' => 'ru'], [
            'Referer' => route('login'),
        ])->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Вход')
            ->assertSeeText('Введите данные внутренней учётной записи.')
            ->assertSeeText('Запомнить меня')
            ->assertSeeText('Войти');

        $this->assertSame('ru', session('locale'));

        $this->withSession(['locale' => 'az'])
            ->post(route('locale.update'), ['locale' => 'en'], [
                'Referer' => route('login'),
            ])
            ->assertUnprocessable();

        $this->assertSame('az', session('locale'));
    }

    public function test_all_russian_and_azerbaijani_dictionaries_have_matching_key_shapes(): void
    {
        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $keys = [...$keys, ...$flatten($value, $path)];
                } else {
                    $keys[] = $path;
                }
            }

            return $keys;
        };

        $ruFiles = glob(base_path('lang/ru/*.php')) ?: [];
        $azFiles = glob(base_path('lang/az/*.php')) ?: [];
        $names = array_unique([...array_map('basename', $ruFiles), ...array_map('basename', $azFiles)]);

        foreach ($names as $name) {
            $ru = file_exists(base_path('lang/ru/'.$name)) ? $flatten(require base_path('lang/ru/'.$name)) : [];
            $az = file_exists(base_path('lang/az/'.$name)) ? $flatten(require base_path('lang/az/'.$name)) : [];
            $this->assertSame([], array_values(array_diff($ru, $az)), $name.' has keys missing in AZ');
            $this->assertSame([], array_values(array_diff($az, $ru)), $name.' has extra keys in AZ');
        }
    }

    public function test_approved_azerbaijani_terminology_has_no_forbidden_variants(): void
    {
        $this->assertSame('Əsas səhifə', __('navigation.dashboard', [], 'az'));
        $this->assertSame('İnvoys filtrləri', __('invoices.index.state', [], 'az'));
        $this->assertSame('Filtrləri sıfırla', __('invoices.index.reset_filters', [], 'az'));

        $az = collect(glob(base_path('lang/az/*.php')) ?: [])
            ->map(fn (string $path): string => file_get_contents($path) ?: '')
            ->implode("\n");

        $this->assertStringNotContainsString('Redakt', $az);
        $this->assertStringNotContainsString('predmet', mb_strtolower($az));
        $this->assertStringNotContainsString('Parol', $az);
        $this->assertStringNotContainsString('İnvoysun vəziyyəti', $az);
    }
}
