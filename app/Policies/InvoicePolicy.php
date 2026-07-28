<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Access\PermissionName;

final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::InvoicesView->value);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::InvoicesCreate->value);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesUpdate->value);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesIssue->value);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesCancel->value);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesDelete->value);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::InvoicesPrint->value);
    }
}
