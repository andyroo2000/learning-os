<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

trait ProjectsAchievementStudySessions
{
    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectChangedStudySessions(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
    ): void {
        $sessions = $this->changedStudySessions($userId);
        if ($sessions->isEmpty()) {
            return;
        }

        $metrics = $this->integerMetrics($projection->metric_values);
        $thresholdDates = $this->thresholdDates($projection->threshold_reached_at);
        $existingProjections = $this->studySessionProjections($sessions);
        $projectionRows = [];
        $now = now();
        $rowContext = ['userId' => $userId, 'now' => $now];

        foreach ($sessions as $session) {
            $counts['studySessions']++;
            $before = $metrics;
            $existing = $existingProjections->get((string) $session->id);
            $fact = $this->studyMetricFacts->forSession($session);
            $this->applyStudySessionDurations($projection, $existing, $fact);
            $projectionRows[] = $this->studySessionProjectionRow($session, $fact, $existing, $rowContext);
            $this->applyStudyDurationMetrics($projection, $metrics);
            $endedAt = CarbonImmutable::instance($session->ended_at);
            $thresholdDates = $this->thresholdCrossings->recordAll($before, $metrics, $endedAt, $thresholdDates);
            if ($projection->latest_study_ended_at === null || $endedAt->gt($projection->latest_study_ended_at)) {
                $projection->latest_study_ended_at = $endedAt;
            }
        }

        $this->persistStudySessionProjections($projectionRows);
        $this->applyStudyMilestones($userId, $metrics, $thresholdDates);
        $projection->metric_values = $metrics;
        $projection->threshold_reached_at = $thresholdDates;
    }

    /** @return Collection<int, StudyActivitySession> */
    private function changedStudySessions(int $userId): Collection
    {
        return StudyActivitySession::query()
            ->leftJoin(
                'achievement_study_session_projections',
                'achievement_study_session_projections.study_activity_session_id',
                '=',
                'study_activity_sessions.id',
            )
            ->where('study_activity_sessions.user_id', $userId)
            ->where(function (Builder $query): void {
                $query->whereNull('achievement_study_session_projections.study_activity_session_id')
                    ->orWhereColumn(
                        'study_activity_sessions.updated_at',
                        '>',
                        'achievement_study_session_projections.source_updated_at',
                    );
            })
            ->select('study_activity_sessions.*')
            ->orderBy('study_activity_sessions.ended_at')
            ->orderBy('study_activity_sessions.id')
            ->get();
    }

    /**
     * @param  Collection<int, StudyActivitySession>  $sessions
     * @return Collection<string, AchievementStudySessionProjection>
     */
    private function studySessionProjections(Collection $sessions): Collection
    {
        return AchievementStudySessionProjection::query()
            ->whereIn(
                'study_activity_session_id',
                $sessions->pluck('id')->map(static fn ($id): string => (string) $id),
            )
            ->get()
            ->keyBy(static fn (AchievementStudySessionProjection $fact): string => (
                (string) $fact->study_activity_session_id
            ));
    }

    /** @param array{conversation_ms:int,listening_ms:int} $fact */
    private function applyStudySessionDurations(
        AchievementProgressProjection $projection,
        ?AchievementStudySessionProjection $existing,
        array $fact,
    ): void {
        $projection->conversation_ms = max(
            0,
            $projection->conversation_ms - ($existing?->conversation_ms ?? 0) + $fact['conversation_ms'],
        );
        $projection->listening_ms = max(
            0,
            $projection->listening_ms - ($existing?->listening_ms ?? 0) + $fact['listening_ms'],
        );
    }

    /** @param array<string, int> $metrics */
    private function applyStudyDurationMetrics(AchievementProgressProjection $projection, array &$metrics): void
    {
        $metrics[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC] = intdiv(
            $projection->conversation_ms,
            3_600_000,
        );
        $metrics[GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC] = intdiv(
            $projection->conversation_ms,
            60_000,
        );
        $metrics[GetAchievementProgressAction::LISTENING_HOUR_METRIC] = intdiv(
            $projection->listening_ms,
            3_600_000,
        );
    }

    /** @param list<array<string, mixed>> $projectionRows */
    private function persistStudySessionProjections(array $projectionRows): void
    {
        collect($projectionRows)->chunk(500)->each(
            static fn ($rows) => DB::table('achievement_study_session_projections')->upsert(
                $rows->all(),
                ['study_activity_session_id'],
                [
                    'user_id',
                    'study_day',
                    'ended_at',
                    'category',
                    'conversation_ms',
                    'listening_ms',
                    'daily_audio_episode',
                    'source_updated_at',
                    'updated_at',
                ],
            ),
        );
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, array<string, string>>  $thresholdDates
     */
    private function applyStudyMilestones(int $userId, array &$metrics, array &$thresholdDates): void
    {
        $studyMilestones = $this->studyMetricFacts->milestones($userId);
        $before = $metrics;
        $metrics[GetAchievementProgressAction::DOUBLE_FEATURE_METRIC] = $studyMilestones['doubleFeature'];
        $metrics[GetAchievementProgressAction::ON_REPEAT_METRIC] = $studyMilestones['repeatDays'];
        $thresholdDates = $this->thresholdCrossings->recordMetric(
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC,
            [
                'before' => $before[GetAchievementProgressAction::DOUBLE_FEATURE_METRIC],
                'after' => $metrics[GetAchievementProgressAction::DOUBLE_FEATURE_METRIC],
            ],
            $studyMilestones['doubleFeatureReachedAt'] === null
                ? []
                : [1 => $studyMilestones['doubleFeatureReachedAt']],
            $thresholdDates,
        );
        $thresholdDates = $this->thresholdCrossings->recordMetric(
            GetAchievementProgressAction::ON_REPEAT_METRIC,
            [
                'before' => $before[GetAchievementProgressAction::ON_REPEAT_METRIC],
                'after' => $metrics[GetAchievementProgressAction::ON_REPEAT_METRIC],
            ],
            $studyMilestones['repeatReachedAt'],
            $thresholdDates,
        );
    }
}
