<?php

namespace App\Domain\Reviews\Values;

use Carbon\CarbonInterface;

final readonly class CardReviewAppendCandidate
{
    public function __construct(
        public CarbonInterface $reviewedAt,
        public string $reviewEventId,
        public bool $hasExplicitId,
        public bool $hasSyncIdentity,
    ) {}
}
