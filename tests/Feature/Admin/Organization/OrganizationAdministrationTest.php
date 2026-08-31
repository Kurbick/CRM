<?php

namespace Tests\Feature\Admin\Organization;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_administrator_can_manage_multiple_organizations(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get(route('admin.organizations.index'))
            ->assertOk()
            ->assertSee('Организации');

        $this->get(route('admin.organizations.create'))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="invoice_number_code"', false);

        $payload = $this->payload('ORG A', 'OA');
        $this->actingAs($administrator)
            ->post(route('admin.organizations.store'), $payload)
            ->assertRedirect();

        $first = Organization::query()->where('name', 'ORG A')->firstOrFail();
        $this->assertTrue($first->is_active);

        $secondPayload = $this->payload('ORG B', 'OB');
        $this->post(route('admin.organizations.store'), $secondPayload)
            ->assertRedirect();

        $second = Organization::query()->where('name', 'ORG B')->firstOrFail();
        $this->assertTrue($second->is_active);

        $this->get(route('admin.organizations.index'))
            ->assertSee('<a href="'.route('admin.organizations.show', $second).'"', false)
            ->assertDontSee('<a href="'.route('admin.organizations.edit', $second).'"', false);
        $this->get(route('admin.organizations.show', $second))
            ->assertDontSee('is_default', false)
            ->assertDontSee('Организация по умолчанию');

        $updated = $this->payload('ORG C', 'OC');
        $this->put(route('admin.organizations.update', $second), $updated)
            ->assertRedirect(route('admin.organizations.show', $second))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('organizations', [
            'id' => $second->id,
            ...$updated,
        ]);
        $this->assertDatabaseCount('organizations', 2);
    }

    public function test_show_displays_real_organization_and_bank_fields_without_duplicate_header(): void
    {
        $organization = Organization::query()->create([
            'name' => 'ZeroLine',
            'legal_name' => 'ZeroLine MMC',
            'voen' => '1502799541',
            'invoice_number_code' => 'ZL',
            'bank_name' => '58 "Paşa Bank" ASC',
            'iban' => 'AZ61PAHA40060AZNHC01900757',
            'bank_voen' => '1700767721',
            'bank_correspondent_account' => 'AZ82NABZ01350100000000007194',
            'bank_code' => '505141',
            'swift' => 'PAHAAZ22',
        ]);

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.organizations.show', $organization));

        $response->assertOk()
            ->assertSeeText('Основная информация')
            ->assertSeeText('Название организации')
            ->assertSeeText('Юридическое название')
            ->assertSeeText('ZeroLine')
            ->assertSeeText('ZeroLine MMC')
            ->assertSeeText('Банковские реквизиты')
            ->assertSeeText('Расчётный счёт (H/h)')
            ->assertSeeText('Корреспондентский счёт (M/h)')
            ->assertSeeText('AZ82NABZ01350100000000007194')
            ->assertDontSee('Организация: ZeroLine', false);
    }

    public function test_edit_contains_and_saves_legal_and_correspondent_account_fields(): void
    {
        $organization = Organization::query()->create($this->payload('ZeroLine', 'ZL'));
        $payload = [
            ...$this->payload('ZeroLine', 'ZL'),
            'legal_name' => 'ZeroLine MMC',
            'bank_correspondent_account' => 'AZ82NABZ01350100000000007194',
        ];

        $this->actingAs($this->administrator())
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee('name="legal_name"', false)
            ->assertSeeText('Юридическое название')
            ->assertSeeText('Расчётный счёт (H/h)')
            ->assertSee('name="bank_correspondent_account"', false)
            ->assertSeeText('Корреспондентский счёт (M/h)');

        $this->put(route('admin.organizations.update', $organization), $payload)
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'legal_name' => 'ZeroLine MMC',
            'bank_correspondent_account' => 'AZ82NABZ01350100000000007194',
        ]);
    }

    public function test_show_displays_empty_organization_fields_as_muted_dashes(): void
    {
        $administrator = $this->administrator();
        $organization = Organization::query()->create(['name' => 'Only Name']);

        $response = $this->actingAs($administrator)->get(route('admin.organizations.show', $organization));

        $response->assertOk()
            ->assertSeeText('Основная информация')
            ->assertSeeText('Банковские реквизиты')
            ->assertSeeText('Only Name')
            ->assertSeeText('—')
            ->assertSeeText('← Назад к организации')
            ->assertDontSee('Организация по умолчанию');
        $content = $response->getContent();
        $this->assertStringNotContainsString('is_default', $content);
    }

    public function test_vat_settings_are_validated_and_saved_without_erasing_an_inactive_rate(): void
    {
        $administrator = $this->administrator();
        $organization = Organization::query()->create($this->payload('VAT Organization', 'VAT'));

        $this->actingAs($administrator)
            ->put(route('admin.organizations.update', $organization), [
                ...$this->payload('VAT Organization', 'VAT'),
                'is_vat_payer' => '1',
            ])
            ->assertSessionHasErrors('vat_rate');

        $this->put(route('admin.organizations.update', $organization), [
            ...$this->payload('VAT Organization', 'VAT'),
            'is_vat_payer' => '1',
            'vat_rate' => '18.125',
        ])->assertSessionHasErrors('vat_rate');

        $this->put(route('admin.organizations.update', $organization), [
            ...$this->payload('VAT Organization', 'VAT'),
            'is_vat_payer' => '1',
            'vat_rate' => '18',
        ])->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_vat_payer' => 1,
            'vat_rate' => '18.00',
        ]);

        $this->put(route('admin.organizations.update', $organization), [
            ...$this->payload('VAT Organization', 'VAT'),
            'is_vat_payer' => '0',
        ])->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'is_vat_payer' => 0,
            'vat_rate' => '18.00',
        ]);

        $this->get(route('admin.organizations.show', $organization))
            ->assertSeeText('Налогообложение')
            ->assertSeeText('Плательщик НДС')
            ->assertSeeText('Нет');
    }

    public function test_index_remains_a_collection_page_with_one_organization(): void
    {
        $organization = Organization::query()->create($this->payload('Only Organization', 'ONLY'));

        $this->actingAs($this->administrator())
            ->get(route('admin.organizations.index'))
            ->assertOk()
            ->assertSeeText('Организации')
            ->assertSeeText('Only Organization')
            ->assertSee('<a href="'.route('admin.organizations.show', $organization).'"', false)
            ->assertDontSee('<a href="'.route('admin.organizations.edit', $organization).'"', false);
    }

    public function test_show_back_link_uses_collection_count_without_changing_destination(): void
    {
        $organization = Organization::query()->create($this->payload('Only Organization', 'ONLY'));
        $indexUrl = route('admin.organizations.index');

        $this->actingAs($this->administrator())
            ->get(route('admin.organizations.show', $organization))
            ->assertSeeText('← Назад к организации')
            ->assertSee('href="'.$indexUrl.'"', false);

        $second = Organization::query()->create($this->payload('Second Org', 'SECOND'));
        $this->get(route('admin.organizations.show', $second))
            ->assertSeeText('← Назад к организациям')
            ->assertSee('href="'.$indexUrl.'"', false);
    }

    public function test_edit_preserves_old_input_and_returns_to_show_when_cancelled(): void
    {
        $administrator = $this->administrator();
        $organization = Organization::query()->create($this->payload('CURRENT', 'CUR'));

        $this->actingAs($administrator)->withSession(['_old_input' => [
            'name' => 'Исправленное название',
            'bank_name' => 'Новый банк',
        ]])->get(route('admin.organizations.edit', $organization))
            ->assertSee('value="Исправленное название"', false)
            ->assertSee('value="Новый банк"', false)
            ->assertSeeText('← Назад к организации')
            ->assertSee('href="'.route('admin.organizations.show', $organization).'"', false);
    }

    public function test_non_administrator_cannot_view_or_update(): void
    {
        $user = User::factory()->create();

        $organization = Organization::query()->create($this->payload('FORBIDDEN', 'FOR'));

        $this->actingAs($user)->get(route('admin.organizations.show', $organization))->assertForbidden();
        $this->get(route('admin.organizations.edit', $organization))->assertForbidden();
        $this->actingAs($user)->put(route('admin.organizations.update', $organization), $this->payload('FORBIDDEN', 'FOR'))
            ->assertForbidden();
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_invoice_numbering_code_must_be_unique(): void
    {
        Organization::query()->create($this->payload('FIRST', 'DUP'));

        $this->actingAs($this->administrator())
            ->post(route('admin.organizations.store'), $this->payload('SECOND', 'DUP'))
            ->assertSessionHasErrors('invoice_number_code');

        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_active_state_can_be_changed(): void
    {
        $organization = Organization::query()->create($this->payload('FIRST', 'ONE'));

        $administrator = $this->administrator();
        $this->actingAs($administrator)
            ->patch(route('admin.organizations.deactivate', $organization))
            ->assertRedirect(route('admin.organizations.show', $organization));
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'is_active' => false]);

        $this->patch(route('admin.organizations.activate', $organization))
            ->assertRedirect(route('admin.organizations.show', $organization));
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'is_active' => true]);
    }

    public function test_singleton_key_is_not_mass_assignable_or_updateable(): void
    {
        $organization = Organization::query()->create($this->payload('FIRST', 'FIRST') + ['singleton_key' => 'other']);

        $this->assertSame(Organization::SINGLETON_KEY, $organization->singleton_key);

        $organization->update(['singleton_key' => 'other']);

        $this->assertSame(Organization::SINGLETON_KEY, $organization->fresh()->singleton_key);
    }

    public function test_collection_and_delete_routes_exist_but_dependencies_are_protected(): void
    {
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.organizations.destroy'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('admin.organizations.index'));
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('admin.organization.show')->methods());
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('admin.organization.edit')->methods());
    }

    /** @return array<string, string> */
    private function payload(string $name, string $code): array
    {
        return [
            'name' => $name,
            'legal_name' => $name.' MMC',
            'voen' => 'V-'.$name,
            'bank_name' => 'Bank '.$name,
            'iban' => 'AZ00-'.$name,
            'bank_correspondent_account' => 'CORR-'.$name,
            'bank_code' => 'C-'.$name,
            'bank_voen' => 'BV-'.$name,
            'swift' => 'S-'.$name,
            'invoice_number_code' => $code,
        ];
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        return $user;
    }
}
