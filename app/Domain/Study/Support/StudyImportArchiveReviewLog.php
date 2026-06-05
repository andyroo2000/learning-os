<?php

namespace App\Domain\Study\Support;

final readonly class StudyImportArchiveReviewLog
{
    public function __construct(
        public int $sourceReviewId,
        public int $sourceCardId,
        public ?int $sourceEase,
        public ?int $sourceInterval,
        public ?int $sourceLastInterval,
        public ?int $sourceFactor,
        public ?int $sourceTimeMs,
        public ?int $sourceReviewType,
    ) {}
}
