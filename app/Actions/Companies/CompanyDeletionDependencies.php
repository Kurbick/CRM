<?php

namespace App\Actions\Companies;

use App\Models\Company;

class CompanyDeletionDependencies
{
    public function hasBlockingDependencies(Company $company): bool
    {
        return $company->contacts()->exists()
            || $company->contracts()->exists()
            || $company->invoices()->exists()
            // A payment currently requires an invoice, so this is defense-in-depth
            // for legacy or future schemas that may allow a direct company payment.
            || $company->payments()->exists()
            || $company->creditBalance()->exists();
    }
}
