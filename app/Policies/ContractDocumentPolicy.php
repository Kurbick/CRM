<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\User;
use App\Support\Access\PermissionName;

final class ContractDocumentPolicy
{
    public function view(User $user, ContractDocument $document): bool
    {
        return $user->can(PermissionName::ContractsView->value);
    }

    public function create(User $user, Contract $contract): bool
    {
        return $user->can(PermissionName::ContractDocumentsUpload->value);
    }

    public function download(User $user, ContractDocument $document): bool
    {
        return $user->can(PermissionName::ContractDocumentsDownload->value);
    }

    public function delete(User $user, ContractDocument $document): bool
    {
        return $user->can(PermissionName::ContractDocumentsDelete->value);
    }
}
