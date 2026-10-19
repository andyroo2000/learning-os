<?php

namespace Tests\Support;

use App\Domain\Study\Enums\StudyActivityKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StudyActivityBenchmarkHistory
{
    public const NOW = '2026-10-19T16:00:00Z';

    /** @return array{sessions: int, durationMs: int, fixtureSha256: string} */
    public static function seed(int $userId, int $count): array
    {
        $rows = [];
        $duration = 0;
        $hash = hash_init('sha256');
        for ($index = 0; $index < $count; $index++) {
            $row = self::session($userId, $index, $count);
            $duration += $row['duration_ms'];
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
            $rows[] = $row;
            // 25 rows × 12 columns stays below SQLite's historical 999-bind limit.
            if (count($rows) === 25) {
                DB::table('study_activity_sessions')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('study_activity_sessions')->insert($rows);
        }

        return ['sessions' => $count, 'durationMs' => $duration, 'fixtureSha256' => hash_final($hash)];
    }

    /** @return array<string, int|string> */
    private static function session(int $userId, int $index, int $count): array
    {
        $activities = [
            StudyActivityKind::CardReview, StudyActivityKind::DailyAudio,
            StudyActivityKind::CardCreation, StudyActivityKind::Reading,
            StudyActivityKind::Conversation, StudyActivityKind::WaniKaniReview,
        ];
        $activity = $activities[$index % count($activities)];
        $now = CarbonImmutable::parse(self::NOW);
        $start = $now->subDays(1_460)->addSeconds((int) floor($index * 1_459 * 86_400 / $count));
        $spanSeconds = 60 + ($index * 97 % 10_800);

        return [
            'id' => sprintf('%026d', $index + $userId * 100_000),
            'user_id' => $userId,
            'client_session_id' => 'benchmark-'.$index,
            'category' => $activity->category()->value,
            'activity' => $activity->value,
            'source' => ['automatic', 'manual', 'calendar'][$index % 3],
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $start->addSeconds($spanSeconds)->format('Y-m-d H:i:s'),
            'duration_ms' => intdiv($spanSeconds * 1_000, 1 + $index % 4) + $index % 7,
            'name' => 'Deterministic history '.$index,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }
}
