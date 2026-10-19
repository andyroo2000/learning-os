<?php

namespace App\Domain\Study\Data;

use Carbon\CarbonImmutable;

final readonly class StudyActivityAnalyticsOptions
{
    public function __construct(
        public ?CarbonImmutable $now = null,
        public ?CarbonImmutable $anchor = null,
        public bool $adaptiveAllTime = false,
    ) {}
}
