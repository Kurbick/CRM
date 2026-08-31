<?php

namespace Tests\Feature\Localization;

use App\Support\Access\PermissionName;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ContractDuplicateNumberValidationTest extends AuthorizationTestCase
{
    public function test_duplicate_contract_number_is_rejected_when_storing(): void
    {
        $company = $this->company('Duplicate contract number company');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        $this->withSession(['locale' => 'ru'])
            ->from(route('contracts.create'))
            ->post(route('contracts.store'), $this->storePayload($company->id, $contract->contract_number))
            ->assertRedirect(route('contracts.create'))
            ->assertSessionHasErrors('contract_number');

        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_duplicate_contract_number_is_rejected_when_updating(): void
    {
        $company = $this->company('Duplicate contract update company');
        $existing = $this->contract($company);
        $target = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsUpdate->value]);

        $this->withSession(['locale' => 'ru'])
            ->from(route('contracts.edit', $target))
            ->put(route('contracts.update', $target), [
                'contract_number' => $existing->contract_number,
                'start_date' => '2026-08-01',
                'status' => 'active',
            ])
            ->assertRedirect(route('contracts.edit', $target))
            ->assertSessionHasErrors('contract_number');

        $this->assertSame($target->contract_number, $target->fresh()->contract_number);
    }

    public function test_russian_duplicate_message_is_rendered_once_under_contract_number(): void
    {
        $response = $this->duplicateStorePage('ru');
        $message = 'Договор с таким номером уже существует.';

        $response->assertOk()->assertSeeText($message);
        $this->assertDuplicateMessageIsRenderedOnceUnderContractNumber($response->getContent(), $message);
    }

    public function test_azerbaijani_duplicate_message_is_rendered_once_under_contract_number(): void
    {
        $response = $this->duplicateStorePage('az');
        $message = 'Bu nömrəli müqavilə artıq mövcuddur.';

        $response->assertOk()->assertSeeText($message);
        $this->assertDuplicateMessageIsRenderedOnceUnderContractNumber($response->getContent(), $message);
    }

    /** @return array<string, mixed> */
    private function storePayload(int $companyId, string $contractNumber): array
    {
        return [
            'company_id' => $companyId,
            'contract_number' => $contractNumber,
            'start_date' => '2026-08-01',
            'status' => 'active',
        ];
    }

    private function duplicateStorePage(string $locale): \Illuminate\Testing\TestResponse
    {
        $company = $this->company('Localized duplicate contract number company');
        $contract = $this->contract($company);
        $this->actingAsPermissions([PermissionName::ContractsCreate->value]);

        return $this->withSession(['locale' => $locale])
            ->from(route('contracts.create'))
            ->followingRedirects()
            ->post(route('contracts.store'), $this->storePayload($company->id, $contract->contract_number));
    }

    private function assertDuplicateMessageIsRenderedOnceUnderContractNumber(string $content, string $message): void
    {
        $this->assertSame(1, substr_count($content, $message));
        $this->assertStringNotContainsString('validation.unique', $content);

        $contractNumberPosition = strpos($content, 'name="contract_number"');
        $messagePosition = strpos($content, $message);
        $startDatePosition = strpos($content, 'name="start_date"');

        $this->assertNotFalse($contractNumberPosition);
        $this->assertNotFalse($messagePosition);
        $this->assertNotFalse($startDatePosition);
        $this->assertGreaterThan($contractNumberPosition, $messagePosition);
        $this->assertLessThan($startDatePosition, $messagePosition);
    }
}
