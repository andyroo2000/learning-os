<?php

namespace App\Domain\Content\Results;

final readonly class ContentGenerationRequestPruneResult
{
    public function __construct(
        public int $candidates,
        public int $deleted,
        public int $skipped,
        public int $failed,
        public bool $dryRun,
    ) {}
}
