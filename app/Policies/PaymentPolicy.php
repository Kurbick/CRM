<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access\PermissionName;

final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PaymentsView->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(PermissionName::PaymentsView->value);
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->can(PermissionName::PaymentsCreate->value);
    }

    public function confirm(User $user, Payment $payment): bool
    {
        return $user->can(PermissionName::PaymentsConfirm->value);
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $user->can(PermissionName::PaymentsCancel->value);
    }
}
