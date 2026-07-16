<?php

namespace App\Domain\Japanese\Data;

use Carbon\CarbonImmutable;

final readonly class WaniKaniPassedKanji
{
    public function __construct(
        public int $subjectId,
        public string $character,
        public CarbonImmutable $passedAt,
    ) {}
}
