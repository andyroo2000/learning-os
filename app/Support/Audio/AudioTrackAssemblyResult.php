<?php

namespace App\Support\Audio;

final readonly class AudioTrackAssemblyResult
{
    /**
     * @param  list<array{unitIndex: int, startTime: int, endTime: int}>  $timingData
     * @param  array{
     *     unitCount: int,
     *     spokenUnitCount: int,
     *     pauseUnitCount: int,
     *     uniqueSynthesisCount: int,
     *     reusedSynthesisCount: int
     * }  $metadata
     */
    public function __construct(
        public string $storagePath,
        public int $durationSeconds,
        public array $timingData,
        public array $metadata,
    ) {}
}
