<?php

namespace App\Domain\Study\Results;

use Illuminate\Support\Collection;

final readonly class DailyAudioTrackGenerationResult
{
    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array<string, int|string|bool>  $metadata
     */
    public function __construct(
        public Collection $units,
        public array $metadata,
    ) {}

    /**
     * @return list<array<string, float|string>>
     */
    public function scriptUnits(): array
    {
        return $this->units
            ->map(fn (DailyAudioScriptUnit $unit): array => $unit->toArray())
            ->values()
            ->all();
    }
}
