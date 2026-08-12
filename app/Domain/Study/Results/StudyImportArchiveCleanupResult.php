<?php

namespace App\Domain\Study\Results;

final readonly class StudyImportArchiveCleanupResult
{
    public function __construct(
        public int $candidates,
        public int $deleted,
        public int $alreadyMissing,
        public int $failed,
        public int $unsafe,
        public bool $dryRun,
    ) {}
}
