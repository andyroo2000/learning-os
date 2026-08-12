<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveMediaCopy
{
    /**
     * @param  array<string, array{entry: StudyImportArchiveMediaEntry, filename: string, path: string}>  $targets
     */
    public function __construct(
        public array $targets,
        public int $skippedCount,
    ) {}
}
