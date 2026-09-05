<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonInterface;

trait BuildsAchievementStudyProjectionRows
{
    /**
     * @param  array<string, mixed>  $fact
     * @param  array{userId:int,now:CarbonInterface}  $context
     * @return array<string, mixed>
     */
    private function studySessionProjectionRow(
        StudyActivitySession $session,
        array $fact,
        ?AchievementStudySessionProjection $existing,
        array $context,
    ): array {
        return [
            'study_activity_session_id' => (string) $session->id,
            'user_id' => $context['userId'],
            ...$fact,
            'source_updated_at' => $session->updated_at,
            'created_at' => $existing?->created_at ?? $context['now'],
            'updated_at' => $context['now'],
        ];
    }
}
