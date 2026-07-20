<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DateInputComponentTest extends TestCase
{
    public function test_component_keeps_iso_submission_and_declares_day_first_display(): void
    {
        $html = Blade::render(
            '<x-form.date-input name="start_date" value="2026-07-20" required />'
        );

        $this->assertStringContainsString('name="start_date"', $html);
        $this->assertStringContainsString('2026-07-20', $html);
        $this->assertStringContainsString('дд/мм/гггг', $html);
        $this->assertStringContainsString('${match[3]}/${match[2]}/${match[1]}', $html);
        $this->assertStringContainsString('${match[3]}-${match[2]}-${match[1]}', $html);
    }

    public function test_component_validates_real_calendar_days_without_iso_date_parsing(): void
    {
        $source = file_get_contents(resource_path('views/components/form/date-input.blade.php'));

        $this->assertStringContainsString('daysInMonth', $source);
        $this->assertStringContainsString('year % 400 === 0', $source);
        $this->assertStringNotContainsString("new Date('", $source);
        $this->assertStringNotContainsString('new Date("', $source);
    }

    public function test_component_supports_empty_nullable_value(): void
    {
        $html = Blade::render('<x-form.date-input name="end_date" :value="null" />');

        $this->assertStringContainsString('name="end_date"', $html);
        $this->assertStringContainsString("iso: ''", $html);
    }

    public function test_native_calendar_is_not_a_named_or_required_form_control(): void
    {
        $html = Blade::render(
            '<x-form.date-input name="start_date" value="2026-07-20" required min="2026-01-01" max="2026-12-31" />'
        );

        preg_match('/<input\s+x-ref="calendar"[^>]*>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The native calendar input was not rendered.');
        $calendar = $matches[0];

        $this->assertDoesNotMatchRegularExpression('/\sname(?:\s*=|\s|>)/i', $calendar);
        $this->assertDoesNotMatchRegularExpression('/\srequired(?:\s*=|\s|>)/i', $calendar);
        $this->assertStringContainsString('form="start_date_detached_calendar"', $calendar);
        $this->assertStringContainsString('aria-label="Открыть календарь"', $html);
        $this->assertStringContainsString('<svg class="h-4 w-4"', $html);
        $this->assertStringNotContainsString('>▣</button>', $html);
    }

    public function test_dynamic_required_and_disabled_are_not_rendered_as_permanent_attributes(): void
    {
        $html = Blade::render(
            '<div x-data="{ active: false }"><x-form.date-input name="period_start" ::required="active" ::disabled="!active" /></div>'
        );

        preg_match('/<input\s+type="text"[^>]*>/s', $html, $matches);

        $this->assertNotEmpty($matches, 'The visible date input was not rendered.');
        $visible = $matches[0];

        $this->assertDoesNotMatchRegularExpression('/\srequired(?:\s*=\s*["\']required["\']|\s|>)/i', $visible);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s*=\s*["\']disabled["\']|\s|>)/i', $visible);
        $this->assertStringContainsString('x-bind:required="required"', $visible);
        $this->assertStringContainsString('x-bind:disabled="disabled"', $visible);
        $this->assertStringContainsString('Boolean(active)', $html);
        $this->assertStringContainsString('Boolean(!active)', $html);
    }

    public function test_invoice_custom_periods_use_dynamic_validation_and_unique_ids(): void
    {
        $source = file_get_contents(resource_path('views/invoices/create.blade.php'));

        $this->assertStringContainsString('::required="isCustomLine(line)"', $source);
        $this->assertStringContainsString('::disabled="!isCustomLine(line)"', $source);
        $this->assertStringContainsString('dynamic-id="`line_${index}_period_start`"', $source);
        $this->assertStringContainsString('dynamic-id="`line_${index}_period_end`"', $source);
        $this->assertStringContainsString('dynamic-name="`lines[${index}][period_start]`"', $source);
        $this->assertStringContainsString('dynamic-name="`lines[${index}][period_end]`"', $source);
        $this->assertStringNotContainsString('x-model="line.period_start" required', $source);
        $this->assertStringNotContainsString('x-model="line.period_end" required', $source);
    }

    public function test_invoice_uses_accessible_custom_company_and_contract_dropdowns(): void
    {
        $source = file_get_contents(resource_path('views/invoices/create.blade.php'));

        $this->assertStringNotContainsString('<select name="contract_id"', $source);
        $this->assertStringContainsString('<input type="hidden" name="contract_id"', $source);
        $this->assertStringContainsString('aria-haspopup="listbox"', $source);
        $this->assertStringContainsString('x-bind:aria-expanded="companyOpen"', $source);
        $this->assertStringContainsString('x-bind:aria-expanded="contractOpen"', $source);
        $this->assertStringContainsString("'rotate-180': companyOpen", $source);
        $this->assertStringContainsString("'rotate-180': contractOpen", $source);
        $this->assertStringContainsString('await this.contractChanged()', $source);
    }

    public function test_visible_views_do_not_keep_legacy_date_formats_or_native_date_fields(): void
    {
        $legacyFormats = [];
        $nativeDateInputs = [];

        foreach (glob(resource_path('views/*.blade.php')) as $path) {
            $this->collectLegacyDateUsage($path, $legacyFormats, $nativeDateInputs);
        }

        foreach (glob(resource_path('views/*/*.blade.php')) as $path) {
            if (str_ends_with($path, 'components/form/date-input.blade.php')) {
                continue;
            }

            $this->collectLegacyDateUsage($path, $legacyFormats, $nativeDateInputs);
        }

        $this->assertSame([], $legacyFormats, implode("\n", $legacyFormats));
        $this->assertSame([], $nativeDateInputs, implode("\n", $nativeDateInputs));
    }

    private function collectLegacyDateUsage(string $path, array &$legacyFormats, array &$nativeDateInputs): void
    {
        $source = file_get_contents($path);

        if (preg_match('/mm\/dd\/yyyy|m\/d\/Y|d\.m\.Y/', $source)) {
            $legacyFormats[] = $path;
        }

        if (preg_match('/type=["\']date["\']/', $source)) {
            $nativeDateInputs[] = $path;
        }
    }
}
