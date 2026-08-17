<?php

namespace Tests\Feature;

use App\Support\Access\PermissionName;
use Illuminate\Support\Carbon;
use Tests\Feature\Authorization\AuthorizationTestCase;

class ApiTimestampContractTest extends AuthorizationTestCase
{
    public function test_api_real_timestamps_are_unambiguous_utc_and_preserve_epoch(): void
    {
        $instant = Carbon::parse('2031-02-03T08:09:10+00:00');
        Carbon::setTestNow($instant);

        try {
            $company = $this->company('API-TIMESTAMP-COMPANY');
            $contract = $this->contract($company);
            $contract->update(['start_date' => '2031-02-03']);
            $this->actingAsPermissions([
                PermissionName::CompaniesView->value,
                PermissionName::ContractsView->value,
            ]);

            $companyResponse = $this->getJson(route('api.companies.show', $company));
            $contractResponse = $this->getJson(route('api.contracts.show', $contract));

            foreach ([$companyResponse, $contractResponse] as $response) {
                $response->assertOk();
                $serialized = $response->json('created_at');

                $this->assertIsString($serialized);
                $this->assertMatchesRegularExpression(
                    '/^2031-02-03T08:09:10(?:\.\d{6})?Z$/',
                    $serialized,
                );
                $this->assertSame($instant->getTimestamp(), Carbon::parse($serialized)->getTimestamp());
            }

            $this->assertSame('2031-02-03T00:00:00.000000Z', $contractResponse->json('start_date'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
