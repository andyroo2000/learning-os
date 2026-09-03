<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use ZipArchive;

final class StudyImportArchiveAccess
{
    public function open(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        return $zip;
    }

    public function declaredEntrySize(ZipArchive $zip, int $index): ?int
    {
        $stat = $zip->statIndex($index);

        if (! is_array($stat) || ! array_key_exists('size', $stat)) {
            return null;
        }

        $size = filter_var($stat['size'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $size === false ? null : $size;
    }
}
