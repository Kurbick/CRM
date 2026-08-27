<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_web_requests_default_to_ru_and_render_the_russian_shell(): void
    {
        $response = $this->actingAs($this->shellUser())->get(route('contracts.index'));

        $response->assertOk()
            ->assertSeeText('Дашборд')
            ->assertSeeText('Компании')
            ->assertSeeText('Договоры')
            ->assertSeeText('Инвойсы')
            ->assertSeeText('Выйти')
            ->assertSeeText('RU')
            ->assertSeeText('AZ')
            ->assertSee('lang="ru"', false)
            ->assertSee('aria-pressed="true"', false);

        $this->assertSame('ru', app()->getLocale());
        $this->assertSame('ru', session('locale'));
    }

    public function test_ru_can_be_selected_and_invalid_session_locale_falls_back_to_ru(): void
    {
        $user = $this->shellUser();

        $this->withSession(['locale' => 'invalid'])
            ->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSeeText('Договоры');

        $this->assertSame('ru', app()->getLocale());
        $this->assertSame('ru', session('locale'));

        $this->post(route('locale.update'), ['locale' => 'az'], [
            'Referer' => route('contracts.index'),
        ])->assertRedirect(route('contracts.index'));

        $this->post(route('locale.update'), ['locale' => 'ru'], [
            'Referer' => route('contracts.index'),
        ])->assertRedirect(route('contracts.index'));

        $this->assertSame('ru', session('locale'));
    }

    public function test_az_can_be_selected_and_persists_between_web_requests(): void
    {
        $user = $this->shellUser();

        $this->actingAs($user);

        $this->post(route('locale.update'), ['locale' => 'az'], [
            'Referer' => route('contracts.index'),
        ])->assertRedirect(route('contracts.index'));

        $response = $this->get(route('contracts.index'));

        $response->assertOk()
            ->assertSeeText('Əsas səhifə')
            ->assertSeeText('Şirkətlər')
            ->assertSeeText('Müqavilələr')
            ->assertSeeText('İnvoyslar')
            ->assertSeeText('Çıx')
            ->assertSeeText('RU')
            ->assertSeeText('AZ')
            ->assertSee('lang="az"', false)
            ->assertSee('aria-pressed="true"', false);

        $this->assertSame('az', app()->getLocale());
        $this->assertSame('az', session('locale'));
    }

    public function test_invalid_locale_is_rejected_without_changing_the_session_preference(): void
    {
        $this->actingAs($this->shellUser());

        $this->withSession(['locale' => 'az'])
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertUnprocessable();

        $this->assertSame('az', session('locale'));
    }

    public function test_locale_switch_does_not_redirect_to_an_external_referer(): void
    {
        $this->actingAs($this->shellUser());

        $this->post(route('locale.update'), ['locale' => 'az'], [
            'Referer' => 'https://evil.test/account',
        ])->assertRedirect(route('dashboard'));
    }

    private function shellUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
        ]);

        return $user;
    }
}
