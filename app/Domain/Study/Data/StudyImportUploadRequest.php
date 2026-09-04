<?php

namespace App\Domain\Study\Data;

use Illuminate\Support\Carbon;

final readonly class StudyImportUploadRequest
{
    public function __construct(
        public int $userId,
        public string $importJobId,
        public string $contentType,
        public Carbon $now,
    ) {}
}
