<?php

namespace App\Domain\Study\Results;

use App\Domain\Study\Support\DailyAudioVoiceRotation;
use Illuminate\Support\Collection;

final readonly class DailyAudioDrillGenerationResult
{
    /** @var Collection<int, DailyAudioScriptUnit> */
    public Collection $units;

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
     *     l2UnitsMissingReadingCount: int,
     *     targetDurationMinutes: int,
     *     availablePromptCount: int,
     *     estimatedDurationSeconds: int,
     *     durationContentExhausted: bool
     * }  $metadata
     */
    public function __construct(
        Collection $units,
        public array $metadata,
    ) {
        $this->units = DailyAudioVoiceRotation::apply($units);
    }

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
