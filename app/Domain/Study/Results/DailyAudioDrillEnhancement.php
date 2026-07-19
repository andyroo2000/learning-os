<?php

namespace App\Domain\Study\Results;

final readonly class DailyAudioDrillEnhancement
{
    /**
     * @param  list<DailyAudioDrillVariation>  $variations
     */
    public function __construct(
        public ?string $englishCue,
        public ?string $exampleJp,
        public ?string $exampleReading,
        public ?string $exampleEn,
        public array $variations,
    ) {}

    public function hasGeneratedContent(): bool
    {
        return ($this->exampleJp !== null && $this->exampleEn !== null)
            || $this->variations !== [];
    }
}
