<?php

namespace App\Domain\Study\Results;

final readonly class DailyAudioDrillVariation
{
    public function __construct(
        public string $kind,
        public string $japanese,
        public ?string $reading,
        public string $english,
    ) {}
}
