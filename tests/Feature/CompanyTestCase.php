<?php

namespace Tests\Feature;

use App\Services\AccessControlSynchronizer;
use Tests\AuthenticatedTestCase;

abstract class CompanyTestCase extends AuthenticatedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();
    }

    /** @param list<string> $permissions */
    protected function grantCompanyPermissions(array $permissions): void
    {
        $this->authenticatedUser->givePermissionTo($permissions);
    }
}
