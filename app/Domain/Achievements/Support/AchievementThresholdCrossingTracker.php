<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Actions\ResolveAchievementEarnedAtAction;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Support\DateTime\ConvoLabTimestamp;
use Carbon\CarbonImmutable;

final class AchievementThresholdCrossingTracker
{
    /** @var array<string, list<int>>|null */
    private ?array $thresholdsByMetric = null;

    public function __construct(
        private readonly GetAchievementCatalogAction $getCatalog,
        private readonly ResolveAchievementEarnedAtAction $resolveEarnedAt,
    ) {}

    /**
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @param  array<string, array<string, string>>  $thresholdDates
     * @return array<string, array<string, string>>
     */
    public function recordAll(
        array $before,
        array $after,
        CarbonImmutable $crossedAt,
        array $thresholdDates,
    ): array {
        foreach ($this->thresholdsByMetric() as $metricKey => $thresholds) {
            foreach ($thresholds as $threshold) {
                if (($before[$metricKey] ?? 0) >= $threshold) {
                    continue;
                }
                if (($after[$metricKey] ?? 0) < $threshold) {
                    continue;
                }

                $key = (string) $threshold;
                if (! isset($thresholdDates[$metricKey][$key])) {
                    $thresholdDates[$metricKey][$key] = ConvoLabTimestamp::serialize($crossedAt);
                }
            }
        }

        return $thresholdDates;
    }

    /**
     * @param  array{before:int,after:int}  $values
     * @param  array<int, CarbonImmutable>  $reachedAtByValue
     * @param  array<string, array<string, string>>  $thresholdDates
     * @return array<string, array<string, string>>
     */
    public function recordMetric(
        string $metricKey,
        array $values,
        array $reachedAtByValue,
        array $thresholdDates,
    ): array {
        foreach ($this->thresholdsByMetric()[$metricKey] ?? [] as $threshold) {
            if ($values['before'] >= $threshold || $values['after'] < $threshold) {
                continue;
            }

            $key = (string) $threshold;
            $crossedAt = $reachedAtByValue[$threshold] ?? null;
            if (! $crossedAt instanceof CarbonImmutable || isset($thresholdDates[$metricKey][$key])) {
                continue;
            }

            $thresholdDates[$metricKey][$key] = ConvoLabTimestamp::serialize($crossedAt);
        }

        return $thresholdDates;
    }

    /** @param array<string, int> $metrics @return array<string, array<string, string>> */
    public function backfill(int $userId, array $metrics): array
    {
        $dates = [];
        foreach ($this->thresholdsByMetric() as $metricKey => $thresholds) {
            foreach ($thresholds as $threshold) {
                if (($metrics[$metricKey] ?? 0) < $threshold) {
                    break;
                }

                $earnedAt = $this->resolveEarnedAt->handle($userId, $metricKey, $threshold);
                if ($earnedAt !== null) {
                    $dates[$metricKey][(string) $threshold] = ConvoLabTimestamp::serialize($earnedAt);
                }
            }
        }

        return $dates;
    }

    /** @return array<string, float> */
    public function masteryThresholds(): array
    {
        return [
            GetAchievementProgressAction::GURU_CARD_METRIC => StudyMasteryLevel::GURU_STABILITY_DAYS,
            GetAchievementProgressAction::MASTER_CARD_METRIC => StudyMasteryLevel::MASTER_STABILITY_DAYS,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => StudyMasteryLevel::ENLIGHTENED_STABILITY_DAYS,
            GetAchievementProgressAction::BURNED_CARD_METRIC => StudyMasteryLevel::BURNED_STABILITY_DAYS,
        ];
    }

    /** @return array<string, list<int>> */
    private function thresholdsByMetric(): array
    {
        if ($this->thresholdsByMetric !== null) {
            return $this->thresholdsByMetric;
        }

        $thresholds = [];
        foreach ($this->getCatalog->handle()['families'] as $family) {
            $thresholds[(string) $family['metricKey']] = array_map(
                static fn (array $tier): int => (int) $tier['threshold'],
                $family['tiers'],
            );
        }

        return $this->thresholdsByMetric = $thresholds;
    }
}
