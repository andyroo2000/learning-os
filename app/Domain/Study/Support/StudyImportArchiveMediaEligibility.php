<?php

namespace App\Domain\Study\Support;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\OriginalFilename;

final class StudyImportArchiveMediaEligibility
{
    public function isImportable(?StudyImportArchiveMediaEntry $entry): bool
    {
        return $entry !== null
            && $entry->hasContent
            && $entry->sizeBytes !== null
            && $entry->sizeBytes >= 1
            && $entry->sizeBytes <= MediaAsset::MAX_JSON_SAFE_SIZE_BYTES
            && $entry->checksumSha256 !== null
            && strlen($entry->checksumSha256) === 64
            && ctype_xdigit($entry->checksumSha256)
            && mb_strlen($entry->sourceMediaRef) <= MediaAsset::MAX_PATH_LENGTH
            && $this->normalizedSourceFilename($entry) !== null;
    }

    public function normalizedSourceFilename(StudyImportArchiveMediaEntry $entry): ?string
    {
        $filename = OriginalFilename::normalize($entry->sourceFilename);

        return $filename === null || mb_strlen($filename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH
            ? null
            : $filename;
    }
}
