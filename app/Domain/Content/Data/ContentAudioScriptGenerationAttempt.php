<?php

namespace App\Domain\Content\Data;

final readonly class ContentAudioScriptGenerationAttempt
{
    public function __construct(
        public string $jobId,
        public string $scriptId,
        public string $episodeId,
        public int $number,
    ) {}
}
