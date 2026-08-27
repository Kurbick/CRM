<?php

namespace Tests\Feature\Admin\Organization;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_administrator_can_setup_and_update_the_single_organization(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get(route('admin.organization.show'))
            ->assertOk()
            ->assertSee('Наша организация')
            ->assertSee(route('admin.organization.edit'))
            ->assertDontSee('name="name"', false);

        $this->get(route('admin.organization.edit'))
            ->assertOk()
            ->assertSee('data-organization-edit-form', false)
            ->assertSee(route('admin.organization.update'))
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="swift"', false);

        $payload = $this->payload('ORG A');
        $this->actingAs($administrator)
            ->put(route('admin.organization.update'), $payload)
            ->assertRedirect(route('admin.organization.show'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('organizations', [
            'singleton_key' => Organization::SINGLETON_KEY,
            ...$payload,
        ]);

        $updated = $this->payload('ORG B');
        $this->put(route('admin.organization.update'), $updated)->assertRedirect();
        $this->assertDatabaseHas('organizations', $updated + [
            'singleton_key' => Organization::SINGLETON_KEY,
        ]);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_show_displays_empty_organization_fields_as_muted_dashes(): void
    {
        $administrator = $this->administrator();
        Organization::query()->create(['name' => 'Only Name']);

        $response = $this->actingAs($administrator)->get(route('admin.organization.show'));

        $response->assertOk()
            ->assertSeeText('Основная информация')
            ->assertSeeText('Банковские реквизиты')
            ->assertSeeText('Only Name')
            ->assertSee('data-organization-empty-value', false)
            ->assertSee('text-gray-400', false)
            ->assertDontSee('name="bank_name"', false);
        $this->assertSame(7, substr_count($response->getContent(), 'data-organization-empty-value'));
    }

    public function test_edit_preserves_old_input_and_returns_to_show_when_cancelled(): void
    {
        $administrator = $this->administrator();
        Organization::query()->create($this->payload('CURRENT'));

        $this->actingAs($administrator)->withSession(['_old_input' => [
            'name' => 'Исправленное название',
            'bank_name' => 'Новый банк',
        ]])->get(route('admin.organization.edit'))
            ->assertSee('value="Исправленное название"', false)
            ->assertSee('value="Новый банк"', false)
            ->assertSee('href="'.route('admin.organization.show').'"', false);
    }

    public function test_non_administrator_cannot_view_or_update(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.organization.show'))->assertForbidden();
        $this->get(route('admin.organization.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.organization.update'), $this->payload('FORBIDDEN'))
            ->assertForbidden();
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_database_unique_marker_prevents_a_second_own_organization(): void
    {
        Organization::query()->create($this->payload('FIRST'));

        $this->expectException(QueryException::class);
        Organization::query()->create($this->payload('SECOND'));
    }

    public function test_database_rejects_a_second_organization_with_another_key(): void
    {
        Organization::query()->create($this->payload('FIRST'));

        $this->expectException(QueryException::class);
        DB::table('organizations')->insert([
            'singleton_key' => 'other',
            'name' => 'SECOND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_singleton_key_is_not_mass_assignable_or_updateable(): void
    {
        $organization = Organization::query()->create($this->payload('FIRST') + ['singleton_key' => 'other']);

        $this->assertSame(Organization::SINGLETON_KEY, $organization->singleton_key);

        $organization->update(['singleton_key' => 'other']);

        $this->assertSame(Organization::SINGLETON_KEY, $organization->fresh()->singleton_key);
    }

    public function test_there_is_no_delete_or_collection_route(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('admin.organization.destroy'));
        $this->assertNull(app('router')->getRoutes()->getByName('admin.organization.index'));
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('admin.organization.show')->methods());
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('admin.organization.edit')->methods());
    }

    /** @return array<string, string> */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'voen' => 'V-'.$name,
            'bank_name' => 'Bank '.$name,
            'iban' => 'AZ00-'.$name,
            'bank_code' => 'C-'.$name,
            'bank_voen' => 'BV-'.$name,
            'swift' => 'S-'.$name,
        ];
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        return $user;
    }
}
