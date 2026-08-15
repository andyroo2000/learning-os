<?php

namespace App\Domain\Study\Support;

use Carbon\CarbonImmutable;

final class StudyActivityDurationAllocator
{
    public function forWindow(
        int $durationMs,
        CarbonImmutable $sessionStart,
        CarbonImmutable $sessionEnd,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        ?CarbonImmutable $scaleEnd = null,
    ): int {
        $overlapStart = $sessionStart->max($windowStart);
        $overlapEnd = $sessionEnd->min($windowEnd);
        if ($overlapEnd->lessThanOrEqualTo($overlapStart)) {
            return 0;
        }

        $elapsedMs = max(1, (int) round(
            $sessionStart->diffInRealMilliseconds($scaleEnd ?? $sessionEnd),
        ));
        $overlapStartMs = (int) round($sessionStart->diffInRealMilliseconds($overlapStart));
        $overlapEndMs = (int) round($sessionStart->diffInRealMilliseconds($overlapEnd));
        $allocatedBefore = (int) round($durationMs * min(1, $overlapStartMs / $elapsedMs));
        $allocatedThrough = (int) round($durationMs * min(1, $overlapEndMs / $elapsedMs));

        return max(0, $allocatedThrough - $allocatedBefore);
    }
}
