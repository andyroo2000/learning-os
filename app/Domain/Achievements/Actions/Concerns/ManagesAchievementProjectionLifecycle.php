<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Models\User;

trait ManagesAchievementProjectionLifecycle
{
    private function lockProjection(int $userId): ?AchievementProgressProjection
    {
        $projection = AchievementProgressProjection::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();

        if ($projection !== null) {
            return $projection;
        }

        // The user row only serializes first-time projection creation. Existing
        // progress reads contend on the purpose-built projection row instead.
        User::query()->select('id')->whereKey($userId)->lockForUpdate()->firstOrFail();

        return AchievementProgressProjection::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();
    }

    private function requiresRebuild(?AchievementProgressProjection $projection): bool
    {
        if ($projection === null) {
            return true;
        }

        if ($projection->projection_version !== self::PROJECTION_VERSION) {
            return true;
        }

        return $projection->needs_rebuild;
    }

    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function rebuild(
        int $userId,
        ?AchievementProgressProjection $projection,
        array &$counts,
    ): AchievementProgressProjection {
        AchievementCardProjection::query()->where('user_id', $userId)->delete();
        AchievementStudySessionProjection::query()->where('user_id', $userId)->delete();

        $metrics = $this->calculateMetrics->handle($userId);
        $reviewState = $this->seedReviewAndCardFacts($userId, $counts);
        $studyState = $this->seedStudyFacts($userId, $counts);

        $projection ??= new AchievementProgressProjection;
        $projection->user_id = $userId;
        $projection->projection_version = self::PROJECTION_VERSION;
        $projection->metric_values = $metrics;
        $projection->threshold_reached_at = $this->thresholdCrossings->backfill($userId, $metrics);
        $projection->current_correct_run = $reviewState['currentRun'];
        $projection->conversation_ms = $studyState['conversationMs'];
        $projection->listening_ms = $studyState['listeningMs'];
        $projection->last_review_created_at = $reviewState['lastCreatedAt'];
        $projection->last_review_id = $reviewState['lastCreatedId'];
        $projection->latest_reviewed_at = $reviewState['latestReviewedAt'];
        $projection->latest_reviewed_id = $reviewState['latestReviewedId'];
        $projection->latest_study_ended_at = $studyState['latestEndedAt'];
        $projection->needs_rebuild = false;
        $projection->saveOrFail();

        return $projection;
    }
}
