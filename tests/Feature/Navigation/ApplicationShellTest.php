<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use App\Support\Access\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_shell_contains_primary_navigation_and_active_nested_section(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::DashboardView->value,
            PermissionName::CompaniesView->value,
            PermissionName::ContractsView->value,
            PermissionName::InvoicesView->value,
        ]);

        $response = $this->actingAs($user)->get(route('contracts.index'));

        $response->assertOk()
            ->assertSee('crm-global-navigation', false)
            ->assertSee('id="crm-sidebar"', false)
            ->assertSee('aria-label="Основная навигация"', false)
            ->assertSeeText('Дашборд')
            ->assertSeeText('Компании')
            ->assertSeeText('Договоры')
            ->assertSeeText('Инвойсы')
            ->assertDontSeeText('Рабочее пространство')
            ->assertDontSeeText('Рабочая область')
            ->assertSee('href="'.route('contracts.index').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_administration_links_are_permission_aware_and_organization_is_admin_only(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionName::UsersView->value);

        $viewerResponse = $this->actingAs($viewer)->get(route('home'));
        $viewerResponse->assertSeeText('Администрирование')
            ->assertSeeText('Пользователи')
            ->assertDontSeeText('Организации')
            ->assertDontSeeText('Группы')
            ->assertDontSeeText('Права доступа');

        $ordinary = User::factory()->create();
        $ordinaryResponse = $this->actingAs($ordinary)->get(route('home'));
        $ordinaryResponse->assertDontSeeText('Администрирование')
            ->assertDontSeeText('Организации')
            ->assertDontSee(route('admin.users.index'), false);

        $administrator = User::factory()->create();
        $administrator->assignRole(SystemRole::Administrator->value);

        $adminResponse = $this->actingAs($administrator)->get(route('home'));
        $adminResponse->assertSeeText('Администрирование')
            ->assertSeeText('Организации')
            ->assertSee('href="'.route('admin.organizations.index').'"', false)
            ->assertSeeText('Пользователи')
            ->assertSeeText('Доступ')
            ->assertDontSee('<span>Группы</span>', false)
            ->assertDontSee('<span>Права доступа</span>', false);
    }

    public function test_user_dropdown_contains_only_password_change_and_logout(): void
    {
        $user = User::factory()->create(['name' => 'Shell User']);

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk()
            ->assertSeeText('Shell User')
            ->assertSee('aria-haspopup="menu"', false)
            ->assertSee(route('password.change'), false)
            ->assertSeeText('Сменить пароль')
            ->assertSeeText('Выйти')
            ->assertDontSeeText('Организации');
        $this->assertSame(1, substr_count($response->getContent(), 'action="'.route('logout').'"'));
    }
}
