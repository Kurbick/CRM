<?php

namespace App\Actions\Contracts;

use App\Models\Contract;

class ContractDeletionDependencies
{
    public function hasBlockingDependencies(Contract $contract): bool
    {
        return $contract->orders()->exists()
            || $contract->subscriptions()->exists()
            || $contract->documents()->exists()
            || $contract->invoices()->exists();
    }
}
