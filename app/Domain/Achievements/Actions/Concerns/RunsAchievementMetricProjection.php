<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementProgressProjection;
use Illuminate\Support\Facades\DB;

trait RunsAchievementMetricProjection
{
    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectMetrics(int $userId, string &$mode, array &$counts): AchievementProgressProjection
    {
        return DB::transaction(
            function () use ($userId, &$mode, &$counts): AchievementProgressProjection {
                return $this->updateProjection($userId, $mode, $counts);
            },
            3,
        );
    }

    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function updateProjection(int $userId, string &$mode, array &$counts): AchievementProgressProjection
    {
        $projection = $this->lockProjection($userId);

        if ($this->requiresRebuild($projection)) {
            $mode = $projection === null ? 'bootstrap' : 'rebuild';

            return $this->rebuild($userId, $projection, $counts);
        }

        $newReviewEvents = $this->newReviewEvents($userId, $projection)
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->get();
        if ($this->hasOutOfOrderReview($projection, $newReviewEvents)) {
            $mode = 'rebuild';

            return $this->rebuild($userId, $projection, $counts);
        }

        if ($this->hasOutOfOrderStudySession($userId, $projection)) {
            $mode = 'rebuild';

            return $this->rebuild($userId, $projection, $counts);
        }

        $this->projectNewReviews($userId, $projection, $counts, $newReviewEvents);
        $this->projectChangedCards($userId, $projection, $counts);
        $this->projectChangedStudySessions($userId, $projection, $counts);
        $projection->needs_rebuild = false;
        $projection->saveOrFail();

        return $projection;
    }
}
