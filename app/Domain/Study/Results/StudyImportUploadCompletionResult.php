<?php

namespace App\Domain\Study\Results;

use App\Domain\Study\Models\StudyImportJob;

final readonly class StudyImportUploadCompletionResult
{
    public function __construct(
        public StudyImportJob $importJob,
        public bool $shouldDispatchImport,
    ) {}
}
