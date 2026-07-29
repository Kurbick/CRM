<?php

namespace App\Support\ContractDocuments;

use Illuminate\Http\UploadedFile;
use ZipArchive;

final class ContractDocumentFileType
{
    public static function serverExtension(UploadedFile $file): ?string
    {
        $guessedExtension = strtolower((string) $file->extension());
        $clientExtension = strtolower((string) pathinfo(
            $file->getClientOriginalPath(),
            PATHINFO_EXTENSION
        ));

        if ($clientExtension !== 'docx') {
            return $guessedExtension !== '' ? $guessedExtension : null;
        }

        if (! in_array($guessedExtension, ['docx', 'zip'], true) || ! self::isSafeDocxPackage($file)) {
            return null;
        }

        return 'docx';
    }

    private static function isSafeDocxPackage(UploadedFile $file): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $archive = new ZipArchive;

        if ($archive->open($file->getRealPath()) !== true) {
            return false;
        }

        try {
            $contentTypes = $archive->getFromName('[Content_Types].xml');

            return is_string($contentTypes)
                && $archive->locateName('_rels/.rels') !== false
                && $archive->locateName('word/document.xml') !== false
                && str_contains(
                    $contentTypes,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
                );
        } finally {
            $archive->close();
        }
    }
}
