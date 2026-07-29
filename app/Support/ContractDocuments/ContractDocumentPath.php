<?php

namespace App\Support\ContractDocuments;

use App\Models\ContractDocument;

final class ContractDocumentPath
{
    public const DISK = 'local';

    public const QUARANTINE_PREFIX = 'contract-documents/.quarantine';

    public static function isAllowed(ContractDocument $document, string $path): bool
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/\p{C}/u', $path) !== 0
        ) {
            return false;
        }

        $segments = explode('/', $path);

        if (
            in_array('', $segments, true)
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
        ) {
            return false;
        }

        $prefixes = [
            "contract-documents/{$document->contract_id}/",
            "contracts/{$document->contract_id}/documents/",
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix) && strlen($path) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }
}
