<?php

namespace App\Domain\Study\Results;

use App\Domain\Study\Support\DailyAudioVoiceRotation;
use Illuminate\Support\Collection;

final readonly class DailyAudioTrackGenerationResult
{
    /** @var Collection<int, DailyAudioScriptUnit> */
    public Collection $units;

    /**
     * @param  Collection<int, DailyAudioScriptUnit>  $units
     * @param  array<string, int|string|bool>  $metadata
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
