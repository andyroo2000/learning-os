<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

trait DetectsStaleAchievementProjections
{
    /** @param Collection<int, CardReviewEvent> $events */
    private function hasOutOfOrderReview(
        AchievementProgressProjection $projection,
        Collection $events,
    ): bool {
        if ($projection->last_review_created_at === null) {
            return false;
        }

        if ($projection->latest_reviewed_at === null) {
            return false;
        }

        if ($events->isEmpty()) {
            return false;
        }

        $event = $events->first();
        $reviewedAt = CarbonImmutable::instance($event->reviewed_at);

        if ($reviewedAt->lt($projection->latest_reviewed_at)) {
            return true;
        }

        if (! $reviewedAt->equalTo($projection->latest_reviewed_at)) {
            return false;
        }

        return strcmp((string) $event->id, (string) $projection->latest_reviewed_id) <= 0;
    }

    private function hasOutOfOrderStudySession(int $userId, AchievementProgressProjection $projection): bool
    {
        if ($projection->latest_study_ended_at === null) {
            return false;
        }

        if ($this->hasHistoricalStudySessionInsertion($userId, $projection)) {
            return true;
        }

        return $this->changedProjectedStudySessions($userId)->contains(
            fn (StudyActivitySession $session): bool => $this->studySessionFactChanged($session),
        );
    }

    private function hasHistoricalStudySessionInsertion(
        int $userId,
        AchievementProgressProjection $projection,
    ): bool {
        return StudyActivitySession::query()
            ->leftJoin(
                'achievement_study_session_projections',
                'achievement_study_session_projections.study_activity_session_id',
                '=',
                'study_activity_sessions.id',
            )
            ->where('study_activity_sessions.user_id', $userId)
            ->whereNull('achievement_study_session_projections.study_activity_session_id')
            ->where('study_activity_sessions.ended_at', '<', $projection->latest_study_ended_at)
            ->exists();
    }

    /** @return Collection<int, StudyActivitySession> */
    private function changedProjectedStudySessions(int $userId): Collection
    {
        return StudyActivitySession::query()
            ->join(
                'achievement_study_session_projections',
                'achievement_study_session_projections.study_activity_session_id',
                '=',
                'study_activity_sessions.id',
            )
            ->where('study_activity_sessions.user_id', $userId)
            ->where(function (Builder $query): void {
                $query->whereNull('achievement_study_session_projections.source_updated_at')
                    ->orWhereColumn(
                        'study_activity_sessions.updated_at',
                        '>',
                        'achievement_study_session_projections.source_updated_at',
                    );
            })
            ->select([
                'study_activity_sessions.*',
                'achievement_study_session_projections.study_day as projected_study_day',
                'achievement_study_session_projections.ended_at as projected_ended_at',
                'achievement_study_session_projections.category as projected_category',
                'achievement_study_session_projections.conversation_ms as projected_conversation_ms',
                'achievement_study_session_projections.listening_ms as projected_listening_ms',
                'achievement_study_session_projections.daily_audio_episode as projected_daily_audio_episode',
            ])
            ->get();
    }

    private function studySessionFactChanged(StudyActivitySession $session): bool
    {
        $fact = $this->studyMetricFacts->forSession($session);

        return $fact['study_day'] !== (string) $session->getAttribute('projected_study_day')
            || ! $fact['ended_at']->equalTo(CarbonImmutable::parse($session->getAttribute('projected_ended_at')))
            || $fact['category'] !== (string) $session->getAttribute('projected_category')
            || $fact['conversation_ms'] !== (int) $session->getAttribute('projected_conversation_ms')
            || $fact['listening_ms'] !== (int) $session->getAttribute('projected_listening_ms')
            || $fact['daily_audio_episode'] !== $session->getAttribute('projected_daily_audio_episode');
    }
}
