<?php

namespace App\Domain\Reviews\Values;

use Carbon\CarbonInterface;

final readonly class LatestCardReviewChronology
{
    public function __construct(
        public ?CarbonInterface $reviewedAt,
        public ?string $reviewEventId,
        public bool $hasSyncIdentity,
    ) {}
}
