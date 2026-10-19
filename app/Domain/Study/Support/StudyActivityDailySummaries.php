<?php

namespace App\Domain\Study\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StudyActivityDailySummaries
{
    public const ALGORITHM_VERSION = 1;

    public function __construct(private readonly StudyActivityDailySummaryBuilder $builder) {}

    /**
     * Every returned entry covers one completed local day. Missing rows,
     * including zero-activity days, are built under the ledger writers' lock.
     * The caller may nest this in the same owner-locked response transaction
     * to combine these days with a consistent raw current-day/hourly snapshot.
     *
     * @return list<array{date: string, startsAt: string, endsAt: string, categories: array<string, int>}>|null
     */
    public function forWindow(int $userId, StudyActivitySummaryWindow $window): ?array
    {
        $days = $window->days();
        if ($days === null || $days === []) {
            return $days;
        }

        return DB::transaction(function () use ($userId, $window, $days): array {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $stored = $this->storedCategories($userId, $window);
            $missing = array_values(array_filter($days, fn (array $day): bool => ! isset($stored[$day['date']])));
            $built = $this->builder->build($userId, $missing);
            $this->persist($userId, $window, $missing, $built);
            $categories = $stored + $built;

            return array_map(fn (array $day): array => [
                'date' => $day['date'],
                'startsAt' => $day['start']->format('Y-m-d\TH:i:s.u\Z'),
                'endsAt' => $day['end']->format('Y-m-d\TH:i:s.u\Z'),
                'categories' => $categories[$day['date']],
            ], $days);
        });
    }

    /** @return array<string, array<string, int>> */
    private function storedCategories(int $userId, StudyActivitySummaryWindow $window): array
    {
        return DB::table('study_activity_daily_summaries')->where('user_id', $userId)
            ->where('timezone', $window->timezone->getName())
            ->where('algorithm_version', self::ALGORITHM_VERSION)
            ->where('local_date', '>=', $window->startsAt->toDateString())
            ->where('local_date', '<', $window->endsAt->toDateString())
            ->pluck('categories', 'local_date')
            ->map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR))
            ->all();
    }

    private function persist(int $userId, StudyActivitySummaryWindow $window, array $days, array $categories): void
    {
        $rows = array_map(fn (array $day): array => [
            'user_id' => $userId,
            'timezone' => $window->timezone->getName(),
            'algorithm_version' => self::ALGORITHM_VERSION,
            'local_date' => $day['date'],
            'started_at' => $day['start']->format('Y-m-d H:i:s.u'),
            'ended_at' => $day['end']->format('Y-m-d H:i:s.u'),
            'categories' => json_encode($categories[$day['date']], JSON_THROW_ON_ERROR),
            'created_at' => $window->now->utc()->format('Y-m-d H:i:s.u'),
            'updated_at' => $window->now->utc()->format('Y-m-d H:i:s.u'),
        ], $days);
        // Nine columns per row: 90 rows remain below SQLite's 999-bind limit.
        foreach (array_chunk($rows, 90) as $chunk) {
            DB::table('study_activity_daily_summaries')->insert($chunk);
        }
    }
}
