<?php

namespace App\Domain\Achievements\Values;

final readonly class AchievementMetricTarget
{
    public function __construct(
        public int $userId,
        public string $metricKey,
        public int $threshold,
    ) {}
}
