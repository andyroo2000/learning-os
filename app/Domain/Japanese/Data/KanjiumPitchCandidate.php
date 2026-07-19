<?php

namespace App\Domain\Japanese\Data;

final readonly class KanjiumPitchCandidate
{
    public function __construct(
        public string $surface,
        public string $reading,
        public int $pitchNumber,
    ) {}
}
