<?php

namespace Tests\Unit;

use App\Http\Controllers\Web\ContractController;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class ContractReturnNavigationTest extends TestCase
{
    public function test_index_edit_link_passes_explicit_whitelisted_context(): void
    {
        $source = file_get_contents(resource_path('views/contracts/index.blade.php'));

        $this->assertStringContainsString("'edit_origin' => 'index'", file_get_contents(app_path('Http/Controllers/Web/ContractController.php')));
        $this->assertStringContainsString('...$contractEditContext', $source);
        $this->assertStringNotContainsString("route('contracts.edit', \$contract)", $source);
    }

    public function test_index_context_preserves_valid_parameters_and_discards_invalid_ones(): void
    {
        $contract = $this->contract();
        foreach (['active', 'terminated'] as $status) {
            $valid = $this->returnContext($contract, [
                'edit_origin' => 'index',
                'search' => ' ABC ',
                'status' => $status,
                'company_id' => '3',
                'sort_by' => 'end_date',
                'sort_direction' => 'asc',
                'page' => '2',
                'return_url' => 'https://evil.example',
            ]);
            $parameters = [
                'search' => 'ABC',
                'status' => $status,
                'company_id' => 3,
                'sort_by' => 'end_date',
                'sort_direction' => 'asc',
                'page' => 2,
            ];

            $this->assertSame(route('contracts.index', $parameters), $valid['url']);
            $this->assertSame(['edit_origin' => 'index', ...$parameters], $valid['hidden']);
            $this->assertStringNotContainsString('evil.example', $valid['url']);
        }

        foreach (['expired', 'invalid'] as $status) {
            $invalid = $this->returnContext($contract, [
                'edit_origin' => 'index',
                'status' => $status,
                'company_id' => '-1',
                'sort_by' => 'drop table contracts',
                'sort_direction' => 'sideways',
                'page' => '0',
            ]);

            $this->assertSame(route('contracts.index'), $invalid['url']);
            $this->assertSame(['edit_origin' => 'index'], $invalid['hidden']);
        }
    }

    public function test_show_context_returns_to_contract_show_and_preserves_company_context(): void
    {
        $contract = $this->contract();
        $plain = $this->returnContext($contract, ['edit_origin' => 'show']);
        $company = $this->returnContext($contract, [
            'edit_origin' => 'show',
            'origin' => 'company',
            'tab' => 'contracts',
        ]);

        $this->assertSame(route('contracts.show', $contract), $plain['url']);
        $this->assertSame(['edit_origin' => 'show'], $plain['hidden']);
        $this->assertSame(route('contracts.show', [
            'contract' => $contract,
            'origin' => 'company',
            'tab' => 'contracts',
        ]), $company['url']);
        $this->assertSame([
            'edit_origin' => 'show',
            'origin' => 'company',
            'tab' => 'contracts',
        ], $company['hidden']);
    }

    public function test_invalid_origin_and_arbitrary_url_fall_back_to_contract_show(): void
    {
        $contract = $this->contract();
        $context = $this->returnContext($contract, [
            'edit_origin' => 'https://evil.example',
            'return_url' => 'https://evil.example/redirect',
            'origin' => 'external',
            'tab' => 'contracts',
        ]);

        $this->assertSame(route('contracts.show', $contract), $context['url']);
        $this->assertSame('contracts.show', $context['route']);
        $this->assertSame(['edit_origin' => 'show'], $context['hidden']);
    }

    public function test_edit_form_and_update_share_the_calculated_return_context(): void
    {
        $edit = file_get_contents(resource_path('views/contracts/edit.blade.php'));
        $show = file_get_contents(resource_path('views/contracts/show.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Web/ContractController.php'));

        $this->assertStringContainsString("\$returnContext['url']", $edit);
        $this->assertStringContainsString("\$returnContext['hidden']", $edit);
        $this->assertStringContainsString("'edit_origin' => 'show'", $show);
        $this->assertStringContainsString("->route(\$returnContext['route'], \$returnContext['route_parameters'])", $controller);
        $this->assertStringNotContainsString('return_url', $edit);
    }

    private function returnContext(Contract $contract, array $parameters): array
    {
        $method = new ReflectionMethod(ContractController::class, 'contractEditReturnContext');

        return $method->invoke(
            new ContractController(),
            Request::create('/contracts/'.$contract->id.'/edit', 'GET', $parameters),
            $contract
        );
    }

    private function contract(): Contract
    {
        $company = new Company(['name' => 'Context Company']);
        $company->id = 3;
        $contract = new Contract(['contract_number' => 'CTR-001']);
        $contract->id = 9;
        $contract->company_id = $company->id;
        $contract->setRelation('company', $company);

        return $contract;
    }
}
