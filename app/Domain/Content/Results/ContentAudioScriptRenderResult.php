<?php

namespace App\Domain\Content\Results;

final readonly class ContentAudioScriptRenderResult
{
    /** @param list<array{unitIndex: int, startTime: int, endTime: int}> $timingData */
    public function __construct(
        public string $speed,
        public float $numericSpeed,
        public string $storagePath,
        public int $durationSeconds,
        public array $timingData,
    ) {}
}
