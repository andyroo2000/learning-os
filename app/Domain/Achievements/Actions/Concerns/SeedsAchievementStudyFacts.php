<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait SeedsAchievementStudyFacts
{
    /**
     * @param  array{reviews:int,cards:int,studySessions:int}  $counts
     * @return array{conversationMs:int,listeningMs:int,latestEndedAt:?CarbonImmutable}
     */
    private function seedStudyFacts(int $userId, array &$counts): array
    {
        $conversationMs = 0;
        $listeningMs = 0;
        $latestEndedAt = null;
        $rows = [];
        $now = now();

        foreach (StudyActivitySession::query()->where('user_id', $userId)->orderBy('id')->cursor() as $session) {
            $counts['studySessions']++;
            $fact = $this->studyMetricFacts->forSession($session);
            $conversationMs += $fact['conversation_ms'];
            $listeningMs += $fact['listening_ms'];
            $endedAt = CarbonImmutable::instance($session->ended_at);
            $latestEndedAt = $latestEndedAt === null || $endedAt->gt($latestEndedAt) ? $endedAt : $latestEndedAt;
            $rows[] = [
                'study_activity_session_id' => (string) $session->id,
                'user_id' => $userId,
                ...$fact,
                'source_updated_at' => $session->updated_at,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                DB::table('achievement_study_session_projections')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('achievement_study_session_projections')->insert($rows);
        }

        return compact('conversationMs', 'listeningMs', 'latestEndedAt');
    }
}
