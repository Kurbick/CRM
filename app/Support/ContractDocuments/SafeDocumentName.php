<?php

namespace App\Support\ContractDocuments;

final class SafeDocumentName
{
    private const MAX_BYTES = 180;

    public static function sanitize(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\'], '', $name);
        $name = trim(mb_strcut($name, 0, self::MAX_BYTES, 'UTF-8'));

        return $name !== '' ? $name : 'document';
    }

    public static function isAcceptableOriginalPath(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_BYTES) {
            return false;
        }

        if (str_contains($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F]/u', $path) === 0;
    }

    public static function extensionMatches(string $originalPath, string $serverExtension): bool
    {
        $clientExtension = strtolower((string) pathinfo($originalPath, PATHINFO_EXTENSION));
        $serverExtension = strtolower($serverExtension);

        if ($clientExtension === '' || $serverExtension === '') {
            return false;
        }

        $aliases = [
            'jpg' => 'jpeg',
            'jpeg' => 'jpeg',
        ];

        return ($aliases[$clientExtension] ?? $clientExtension)
            === ($aliases[$serverExtension] ?? $serverExtension);
    }
}
