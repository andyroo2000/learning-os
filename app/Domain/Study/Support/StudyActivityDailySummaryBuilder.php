<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Enums\StudyActivityCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StudyActivityDailySummaryBuilder
{
    /**
     * @param  list<array{date: string, start: CarbonImmutable, end: CarbonImmutable, startUs: int, endUs: int}>  $days
     * @return array<string, array<string, int>>
     */
    public function build(int $userId, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $totals = array_fill_keys(array_column($days, 'date'), $this->emptyCategories());
        $sessions = DB::table('study_activity_sessions')->where('user_id', $userId)
            ->where('ended_at', '>', $days[0]['start']->format('Y-m-d H:i:s.u'))
            ->where('started_at', '<', $days[array_key_last($days)]['end']->format('Y-m-d H:i:s.u'))
            ->select(['category', 'started_at', 'ended_at', 'duration_ms'])->cursor();
        foreach ($sessions as $session) {
            $allocation = new StudyActivityIntervalAllocation(
                (int) $session->duration_ms,
                StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($session->started_at, 'UTC')),
                StudyActivityIntervalAllocation::microseconds(CarbonImmutable::parse($session->ended_at, 'UTC')),
            );
            $category = StudyActivityCategory::from($session->category)->value;
            $this->accumulate($totals, $days, $allocation, $category);
        }

        return $totals;
    }

    private function accumulate(array &$totals, array $days, StudyActivityIntervalAllocation $allocation, string $category): void
    {
        $count = count($days);
        for ($index = $this->firstOverlap($days, $allocation->startedAtMicroseconds); $index < $count; $index++) {
            $day = $days[$index];
            if ($day['startUs'] >= $allocation->recordedEndMicroseconds) {
                break;
            }
            $totals[$day['date']][$category] += $allocation->forWindow($day['startUs'], $day['endUs']);
        }
    }

    private function firstOverlap(array $days, int $startedAt): int
    {
        $low = 0;
        $high = count($days);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($days[$middle]['endUs'] <= $startedAt) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /** @return array<string, int> */
    private function emptyCategories(): array
    {
        return array_fill_keys(array_map(
            fn (StudyActivityCategory $category): string => $category->value,
            StudyActivityCategory::cases(),
        ), 0);
    }
}
