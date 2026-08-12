<?php

namespace App\Domain\Study\Support;

use InvalidArgumentException;

final class StudyImportArchiveExpansionPolicy
{
    public function maxCollectionDatabaseBytes(): int
    {
        return $this->positiveInteger('max_collection_database_bytes');
    }

    public function maxMediaManifestBytes(): int
    {
        return $this->positiveInteger('max_media_manifest_bytes');
    }

    public function maxIndividualMediaBytes(): int
    {
        return $this->positiveInteger('max_individual_media_bytes');
    }

    public function maxTotalMediaBytes(): int
    {
        return $this->positiveInteger('max_total_media_bytes');
    }

    public function addMediaBytes(int $consumedBytes, int $entryBytes): int
    {
        $maxBytes = $this->maxTotalMediaBytes();

        if ($consumedBytes < 0 || $entryBytes < 0 || $consumedBytes > $maxBytes || $entryBytes > $maxBytes - $consumedBytes) {
            throw new InvalidArgumentException('Study import media expansion exceeds its configured byte budget.');
        }

        return $consumedBytes + $entryBytes;
    }

    private function positiveInteger(string $key): int
    {
        $value = config('study_import.archive_expansion.'.$key);
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($validated === false) {
            throw new InvalidArgumentException('Study import archive expansion limit "'.$key.'" must be a positive integer.');
        }

        return $validated;
    }
}
