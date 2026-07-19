<?php

namespace App\Domain\Study\Results;

use Illuminate\Support\Collection;

final readonly class DailyAudioDrillGenerationResult
{
    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array{
     *     enhancedAtomCount: int,
     *     generatedPromptCount: int,
     *     fallbackPromptCount: int,
     *     missingCueCount: int,
     *     totalPromptCount: int,
     *     unitCount: int,
     *     l2UnitCount: int,
     *     l2UnitsWithReadingCount: int,
     *     l2UnitsMissingReadingCount: int
     * }  $metadata
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
