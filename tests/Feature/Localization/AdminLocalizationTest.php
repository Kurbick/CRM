<?php

namespace Tests\Feature\Localization;

use App\Models\Role;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_admin_navigation_and_pages_render_in_russian_and_azerbaijani(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $target = User::factory()->create();
        $target->assignRole('viewer');

        $ru = $this->actingAs($admin)->withSession(['locale' => 'ru'])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeText('Администрирование')
            ->assertSeeText('Организации')
            ->assertSeeText('Пользователи')
            ->assertSeeText('Доступ')
            ->assertSeeText('Только просмотр');

        $az = $this->withSession(['locale' => 'az'])
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSeeText('İdarəetmə')
            ->assertSeeText('Təşkilatlar')
            ->assertSeeText('İstifadəçilər')
            ->assertSeeText('İcazələr')
            ->assertSeeText('Yalnız baxış')
            ->assertSeeText('Şifrə');

        $this->assertStringNotContainsString('Redakt', $az->getContent());
        $this->assertStringNotContainsString('Parol', $az->getContent());

        $organization = Organization::query()->create([
            'name' => 'ZeroLine',
            'invoice_number_code' => 'ZL',
            'is_active' => true,
        ]);
        $nonDefault = Organization::query()->create([
            'name' => 'Maksim Ermakov',
            'invoice_number_code' => 'ME',
            'is_active' => true,
        ]);
        $this->get(route('admin.organizations.index'))
            ->assertSeeText('Təşkilatlar');
        $this->withSession(['locale' => 'ru'])
            ->get(route('admin.organizations.show', $organization))
            ->assertSeeText('Юридическое название')
            ->assertSeeText('Расчётный счёт (H/h)')
            ->assertSeeText('Корреспондентский счёт (M/h)');
        $azShow = $this->withSession(['locale' => 'az'])
            ->get(route('admin.organizations.show', $organization))
            ->assertSeeText('← Təşkilatlara qayıt')
            ->assertSeeText('Düzəliş et')
            ->assertSeeText('Hüquqi ad')
            ->assertSeeText('Hesablaşma hesabı (H/h)')
            ->assertSeeText('Müxbir hesabı (M/h)')
            ->assertDontSee('Standart təşkilat');
        $this->get(route('admin.organizations.edit', $organization))
            ->assertSeeText('← Təşkilata qayıt');

        $this->get(route('admin.organizations.show', $nonDefault))
            ->assertSeeText('← Təşkilatlara qayıt')
            ->assertDontSee('Standart təşkilat');

        $this->get(route('admin.organization.show'))
            ->assertSeeText('Təşkilatımız')
            ->assertSeeText('Bank rekvizitləri');
        $this->get(route('admin.users.edit', $target))
            ->assertSeeText('İstifadəçi')
            ->assertSeeText('Şifrəni sıfırla')
            ->assertSeeText('Düzəliş');
        $this->get(route('admin.users.create'))
            ->assertSeeText('Tam sistem girişi.')
            ->assertDontSeeText('Полный системный доступ.');
    }

    public function test_access_workspace_localizes_groups_and_permissions_without_changing_identities(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $role = Role::findByName('accountant');

        $response = $this->actingAs($admin)->withSession(['locale' => 'az'])
            ->get(route('admin.access-permissions.index', ['role' => $role->id]))
            ->assertOk()
            ->assertSeeText('İcazələr')
            ->assertSeeText('Qruplar')
            ->assertSeeText('Müqavilə xidmətləri')
            ->assertSeeText('İnvoysu rəsmiləşdirmə')
            ->assertSee('value="invoices.issue"', false)
            ->assertSee('value="access_permissions.update"', false);

        $this->assertStringNotContainsString('predmet', mb_strtolower($response->getContent()));
        $this->assertStringNotContainsString('Redakt', $response->getContent());
        $this->assertSame('accountant', $role->fresh()->name);
        $this->assertContains('invoices.issue', PermissionRegistry::names());
    }

    public function test_non_admin_cannot_see_admin_navigation_or_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['locale' => 'az'])
            ->get(route('home'))
            ->assertOk()
            ->assertDontSeeText('İdarəetmə')
            ->assertDontSeeText('İstifadəçilər');

        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.access-permissions.index'))->assertForbidden();
    }
}
