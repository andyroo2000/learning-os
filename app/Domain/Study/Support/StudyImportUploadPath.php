<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyImportJob;

final class StudyImportUploadPath
{
    private function __construct() {}

    public static function forImportJob(int $userId, string $importJobId, string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));

        return StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$userId.'/'.$importJobId.'/'.$filename;
    }
}
