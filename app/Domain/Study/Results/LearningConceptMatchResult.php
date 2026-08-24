<?php

namespace App\Domain\Study\Results;

final readonly class LearningConceptMatchResult
{
    public function __construct(
        public int $conceptCount,
        public int $persistedCount,
    ) {}
}
