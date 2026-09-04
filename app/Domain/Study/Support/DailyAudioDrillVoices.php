<?php

namespace App\Domain\Study\Support;

final readonly class DailyAudioDrillVoices
{
    public function __construct(
        public string $narrator,
        public string $speaker,
    ) {}
}
