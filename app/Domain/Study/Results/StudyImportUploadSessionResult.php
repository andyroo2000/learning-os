<?php

namespace App\Domain\Study\Results;

use App\Domain\Study\Models\StudyImportJob;

final class StudyImportUploadSessionResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly StudyImportJob $importJob,
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
    ) {}
}
