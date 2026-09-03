<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveMediaSource
{
    public function __construct(
        public string $reference,
        public int $declaredSize,
    ) {}
}
