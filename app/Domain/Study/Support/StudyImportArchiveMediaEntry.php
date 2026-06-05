<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveMediaEntry
{
    public function __construct(
        public string $sourceMediaRef,
        public string $sourceFilename,
        public bool $hasContent,
    ) {}
}
