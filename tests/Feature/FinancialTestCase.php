<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\PermissionName;
use Tests\AuthenticatedTestCase;

abstract class FinancialTestCase extends AuthenticatedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(AccessControlSynchronizer::class)->sync();

        Organization::query()->updateOrCreate(
            ['singleton_key' => Organization::SINGLETON_KEY],
            ['name' => 'Test Organization'],
        );

        $this->authenticatedUser->givePermissionTo([
            PermissionName::InvoicesView->value,
            PermissionName::InvoicesCreate->value,
            PermissionName::InvoicesUpdate->value,
            PermissionName::InvoicesIssue->value,
            PermissionName::InvoicesCancel->value,
            PermissionName::InvoicesDelete->value,
            PermissionName::InvoicesPrint->value,
            PermissionName::PaymentsView->value,
            PermissionName::PaymentsCreate->value,
            PermissionName::PaymentsConfirm->value,
            PermissionName::PaymentsCancel->value,
        ]);
    }
}
