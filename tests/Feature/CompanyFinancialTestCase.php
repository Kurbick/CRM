<?php

namespace Tests\Feature;

use App\Support\Access\PermissionName;

abstract class CompanyFinancialTestCase extends FinancialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticatedUser->givePermissionTo(
            [
                PermissionName::CompaniesView->value,
                PermissionName::CompaniesFinancialsView->value,
            ]
        );
    }
}
