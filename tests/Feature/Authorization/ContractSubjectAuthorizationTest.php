<?php

namespace Tests\Feature\Authorization;

use App\Support\Access\PermissionName;
use Illuminate\Support\Facades\Route;

class ContractSubjectAuthorizationTest extends AuthorizationTestCase
{
    public function test_selector_requires_create_permission_and_does_not_create_data(): void
    {
        $contract = $this->contract($this->company());
        $this->actingAsPermissions();
        $this->get(route('contracts.subjects.create', $contract))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_selector_shows_both_typed_options_with_minimal_safe_context(): void
    {
        $company = $this->company('Selector company');
        $company->forceFill([
            'voen' => 'SELECTOR-SECRET-VOEN',
            'iban' => 'AZ00SELECTORSECRET',
            'email' => 'selector-secret@example.test',
            'comment' => 'SELECTOR-SECRET-COMMENT',
        ])->save();
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);

        $this->get(route('contracts.subjects.create', $contract))
            ->assertOk()
            ->assertSee('Разовая услуга')
            ->assertSee('Подписка')
            ->assertSee(route('contracts.orders.create', $contract), false)
            ->assertSee(route('contracts.subscriptions.create', $contract), false)
            ->assertSee($contract->contract_number)
            ->assertSee($company->name)
            ->assertSee(route('home'), false)
            ->assertDontSee('href="'.route('contracts.show', $contract).'"', false)
            ->assertDontSee('href="'.route('companies.show', $company).'"', false)
            ->assertDontSee('SELECTOR-SECRET-VOEN')
            ->assertDontSee('AZ00SELECTORSECRET')
            ->assertDontSee('selector-secret@example.test')
            ->assertDontSee('SELECTOR-SECRET-COMMENT');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_general_subject_post_route_is_absent(): void
    {
        $this->assertFalse(Route::has('contracts.subjects.store'));

        $contract = $this->contract($this->company());
        $this->actingAsPermissions([PermissionName::ContractSubjectsCreate->value]);
        $this->post("/contracts/{$contract->id}/subjects", [])->assertNotFound();
    }
}
