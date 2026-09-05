<?php

namespace App\Domain\Achievements\Actions\Concerns;

trait NormalizesAchievementProjectionValues
{
    /** @param mixed $metrics @return array<string, int> */
    private function integerMetrics(mixed $metrics): array
    {
        return collect(is_array($metrics) ? $metrics : [])->mapWithKeys(
            static fn (mixed $value, mixed $key): array => [(string) $key => (int) $value],
        )->all();
    }

    /** @param mixed $dates @return array<string, array<string, string>> */
    private function thresholdDates(mixed $dates): array
    {
        return collect(is_array($dates) ? $dates : [])->mapWithKeys(
            static fn (mixed $value, mixed $key): array => [
                (string) $key => collect(is_array($value) ? $value : [])->mapWithKeys(
                    static fn (mixed $date, mixed $threshold): array => [(string) $threshold => (string) $date],
                )->all(),
            ],
        )->all();
    }
}
