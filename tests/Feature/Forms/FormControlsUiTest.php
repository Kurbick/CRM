<?php

namespace Tests\Feature\Forms;

use App\Models\User;
use App\Services\AccessControlSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormControlsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    public function test_authenticated_forms_load_shared_control_and_button_styles(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole('administrator');

        $response = $this->actingAs($administrator)->get(route('admin.organization.edit'));

        $response->assertOk()
            ->assertSee('crm-form-scope', false)
            ->assertSee('.crm-form-scope select', false)
            ->assertSee('background-image: url(', false)
            ->assertSee('input[readonly]', false)
            ->assertSee('input:disabled', false)
            ->assertSee('input.border-red-300', false)
            ->assertSee('button.bg-blue-600', false)
            ->assertSee('button.border-red-300', false);
    }

    public function test_guest_password_forms_use_the_same_control_language(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('crm-form-scope', false)
            ->assertSee('class="crm-control crm-control-with-action', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);
    }

    public function test_leading_icon_controls_use_shared_left_spacing(): void
    {
        $styles = file_get_contents(resource_path('views/components/forms/styles.blade.php'));

        $this->assertIsString($styles);
        $this->assertStringContainsString(
            '.crm-form-scope input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([aria-hidden=true]).crm-control-with-leading-icon',
            $styles,
        );
        $this->assertStringContainsString('padding-left: 2.5rem;', $styles);
        $this->assertStringContainsString('.crm-form-scope .crm-filter-neutral', $styles);
        $this->assertStringContainsString('.crm-form-scope .crm-filter-selected', $styles);

        foreach (['companies', 'contracts', 'invoices'] as $section) {
            $view = file_get_contents(resource_path("views/{$section}/index.blade.php"));

            $this->assertIsString($view);
            $this->assertStringContainsString('crm-control-with-leading-icon', $view);
        }
    }
}
