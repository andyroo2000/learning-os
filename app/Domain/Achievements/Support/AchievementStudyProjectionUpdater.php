<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AchievementStudyProjectionUpdater
{
    public function __construct(
        private AchievementStudyMetricFacts $studyMetricFacts,
        private AchievementThresholdCrossingTracker $thresholdCrossings,
    ) {}

    /**
     * @param  array{reviews:int,cards:int,studySessions:int}  $counts
     * @return array{conversationMs:int,listeningMs:int,latestEndedAt:?CarbonImmutable}
     */
    public function seed(int $userId, array &$counts): array
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

    public function hasOutOfOrderSession(int $userId, AchievementProgressProjection $projection): bool
    {
        if ($projection->latest_study_ended_at === null) {
            return false;
        }

        $hasHistoricalInsertion = StudyActivitySession::query()
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
        if ($hasHistoricalInsertion) {
            return true;
        }

        $changedSessions = StudyActivitySession::query()
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

        return $changedSessions->contains(function (StudyActivitySession $session): bool {
            $fact = $this->studyMetricFacts->forSession($session);

            return $fact['study_day'] !== (string) $session->getAttribute('projected_study_day')
                || ! $fact['ended_at']->equalTo(
                    CarbonImmutable::parse($session->getAttribute('projected_ended_at')),
                )
                || $fact['category'] !== (string) $session->getAttribute('projected_category')
                || $fact['conversation_ms'] !== (int) $session->getAttribute('projected_conversation_ms')
                || $fact['listening_ms'] !== (int) $session->getAttribute('projected_listening_ms')
                || $fact['daily_audio_episode'] !== $session->getAttribute('projected_daily_audio_episode');
        });
    }

    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    public function projectChanged(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
    ): void {
        $sessions = $this->changedSessions($userId);
        if ($sessions->isEmpty()) {
            return;
        }

        $metrics = AchievementProjectionValues::integerMetrics($projection->metric_values);
        $thresholdDates = AchievementProjectionValues::thresholdDates($projection->threshold_reached_at);
        $existingProjections = AchievementStudySessionProjection::query()
            ->whereIn(
                'study_activity_session_id',
                $sessions->pluck('id')->map(static fn ($id): string => (string) $id),
            )
            ->get()
            ->keyBy(static fn (AchievementStudySessionProjection $fact): string => (
                (string) $fact->study_activity_session_id
            ));
        $projectionRows = [];
        $now = now();
        foreach ($sessions as $session) {
            $counts['studySessions']++;
            $before = $metrics;
            $existing = $existingProjections->get((string) $session->id);
            $fact = $this->studyMetricFacts->forSession($session);
            $projection->conversation_ms = max(
                0,
                $projection->conversation_ms - ($existing?->conversation_ms ?? 0) + $fact['conversation_ms'],
            );
            $projection->listening_ms = max(
                0,
                $projection->listening_ms - ($existing?->listening_ms ?? 0) + $fact['listening_ms'],
            );
            $projectionRows[] = [
                'study_activity_session_id' => (string) $session->id,
                'user_id' => $userId,
                ...$fact,
                'source_updated_at' => $session->updated_at,
                'created_at' => $existing?->created_at ?? $now,
                'updated_at' => $now,
            ];

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
            $endedAt = CarbonImmutable::instance($session->ended_at);
            $thresholdDates = $this->thresholdCrossings->recordAll($before, $metrics, $endedAt, $thresholdDates);
            if ($projection->latest_study_ended_at === null || $endedAt->gt($projection->latest_study_ended_at)) {
                $projection->latest_study_ended_at = $endedAt;
            }
        }
        $this->persistSessions($projectionRows);
        $this->applyMilestones($userId, $metrics, $thresholdDates);

        $projection->metric_values = $metrics;
        $projection->threshold_reached_at = $thresholdDates;
    }

    /** @return Collection<int, StudyActivitySession> */
    private function changedSessions(int $userId): Collection
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

    /** @param list<array<string, mixed>> $projectionRows */
    private function persistSessions(array $projectionRows): void
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
    private function applyMilestones(int $userId, array &$metrics, array &$thresholdDates): void
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
