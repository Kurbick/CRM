<?php

namespace Tests\Unit;

use App\Http\Controllers\Web\CompanyController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class CompanyReturnNavigationTest extends TestCase
{
    public function test_company_links_from_lists_include_the_complete_current_url(): void
    {
        $invoiceIndex = file_get_contents(resource_path('views/invoices/index.blade.php'));
        $contractIndex = file_get_contents(resource_path('views/contracts/index.blade.php'));
        $companyIndex = file_get_contents(resource_path('views/companies/index.blade.php'));

        $this->assertStringContainsString("'return_url' => request()->fullUrl()", $invoiceIndex);
        $this->assertStringContainsString("'return_url' => request()->fullUrl()", $contractIndex);
        $this->assertStringContainsString("'return_url' => request()->fullUrl()", $companyIndex);
    }

    public function test_company_controller_restricts_return_url_to_internal_list_routes(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Web/CompanyController.php'));

        $this->assertStringContainsString("str_starts_with(\$candidate, '//')", $source);
        $this->assertStringContainsString("strtolower(\$parts['scheme']) !== strtolower(\$request->getScheme())", $source);
        $this->assertStringContainsString("strtolower(\$parts['host']) !== strtolower(\$request->getHost())", $source);
        $this->assertStringContainsString("parse_url(route('invoices.index'), PHP_URL_PATH)", $source);
        $this->assertStringContainsString("parse_url(route('contracts.index'), PHP_URL_PATH)", $source);
        $this->assertStringContainsString("parse_url(route('companies.index'), PHP_URL_PATH)", $source);
    }

    public function test_edit_flow_uses_explicit_safe_origin_context(): void
    {
        $show = file_get_contents(resource_path('views/companies/show.blade.php'));
        $edit = file_get_contents(resource_path('views/companies/edit.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Web/CompanyController.php'));

        $this->assertStringContainsString("\$returnContext['label']", $show);
        $this->assertStringContainsString("'origin' => 'show'", $show);
        $this->assertStringContainsString("\$returnContext['hidden']", $edit);
        $this->assertStringContainsString("\$returnContext['url']", $edit);
        $this->assertStringContainsString("\$request->input('origin') !== 'index'", $controller);
        $this->assertStringContainsString("route('companies.index', \$parameters)", $controller);
        $this->assertStringNotContainsString('return_url', $edit);
    }

    public function test_safe_return_context_preserves_internal_query_and_rejects_external_host(): void
    {
        $method = new ReflectionMethod(CompanyController::class, 'companyReturnContext');
        $controller = new CompanyController();
        $internalUrl = route('invoices.index', [
            'status' => 'draft',
            'sort' => 'due_date',
            'direction' => 'asc',
            'page' => 2,
        ]);
        $internalRequest = Request::create('/companies/1', 'GET', ['return_url' => $internalUrl]);
        $externalRequest = Request::create('/companies/1', 'GET', [
            'return_url' => 'https://example.com/invoices?status=draft&page=2',
        ]);

        $internal = $method->invoke($controller, $internalRequest);
        $external = $method->invoke($controller, $externalRequest);

        $this->assertSame($internalUrl, $internal['url']);
        $this->assertSame('Назад к инвойсам', $internal['label']);
        $this->assertTrue($internal['is_contextual']);
        $this->assertSame(route('companies.index'), $external['url']);
        $this->assertSame('Назад к компаниям', $external['label']);
        $this->assertFalse($external['is_contextual']);
    }
}
