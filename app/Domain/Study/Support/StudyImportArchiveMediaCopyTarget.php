<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveMediaCopyTarget
{
    public function __construct(
        public string $sourceMediaRef,
        public string $targetPath,
    ) {}
}
