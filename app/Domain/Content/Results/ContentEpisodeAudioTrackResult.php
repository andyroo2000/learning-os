<?php

namespace App\Domain\Content\Results;

final readonly class ContentEpisodeAudioTrackResult
{
    /** @param array<string, array{startTime: int, endTime: int}> $sentenceTimings */
    public function __construct(
        public string $track,
        public string $storagePath,
        public int $durationSeconds,
        public array $sentenceTimings,
    ) {}
}
