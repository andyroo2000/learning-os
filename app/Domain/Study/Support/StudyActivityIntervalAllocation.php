<?php

namespace App\Domain\Study\Support;

use Carbon\CarbonImmutable;

final readonly class StudyActivityIntervalAllocation
{
    private int $elapsedMs;

    public function __construct(
        private int $durationMs,
        public int $startedAtMicroseconds,
        public int $recordedEndMicroseconds,
    ) {
        $this->elapsedMs = max(1, (int) round(($recordedEndMicroseconds - $startedAtMicroseconds) / 1_000));
    }

    public static function microseconds(CarbonImmutable $date): int
    {
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }

    public function forWindow(int $startsAtMicroseconds, int $endsAtMicroseconds): int
    {
        $overlapStart = max($this->startedAtMicroseconds, $startsAtMicroseconds);
        $overlapEnd = min($this->recordedEndMicroseconds, $endsAtMicroseconds);
        if ($overlapEnd <= $overlapStart) {
            return 0;
        }

        // Round offsets relative to the session, rather than rounding absolute
        // timestamps first. Cumulative differences conserve every millisecond.
        return max(0, $this->allocatedThrough($overlapEnd) - $this->allocatedThrough($overlapStart));
    }

    private function allocatedThrough(int $microseconds): int
    {
        $offsetMs = (int) round(($microseconds - $this->startedAtMicroseconds) / 1_000);

        return (int) round($this->durationMs * min(1, $offsetMs / $this->elapsedMs));
    }
}
