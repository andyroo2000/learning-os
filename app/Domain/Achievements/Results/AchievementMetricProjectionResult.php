<?php

namespace App\Domain\Achievements\Results;

final readonly class AchievementMetricProjectionResult
{
    /**
     * @param  array<string, int>  $metricValues
     * @param  array<string, array<string, string>>  $thresholdReachedAt
     */
    public function __construct(
        public array $metricValues,
        public array $thresholdReachedAt,
    ) {}
}
