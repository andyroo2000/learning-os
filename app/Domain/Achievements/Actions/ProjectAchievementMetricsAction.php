<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Achievements\Results\AchievementMetricProjectionResult;
use App\Domain\Achievements\Support\AchievementThresholdCrossingTracker;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

final class ProjectAchievementMetricsAction
{
    // Bump this whenever catalog metric keys or thresholds change so existing
    // users rebuild the persisted crossing dates required for reconciliation.
    private const PROJECTION_VERSION = 1;

    public function __construct(
        private readonly CalculateAchievementMetricsAction $calculateMetrics,
        private readonly AchievementThresholdCrossingTracker $thresholdCrossings,
    ) {}

    public function handle(int $userId): AchievementMetricProjectionResult
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Achievement progress user ID must be positive.');
        }

        $startedAt = hrtime(true);
        $mode = 'incremental';
        $counts = ['reviews' => 0, 'cards' => 0, 'studySessions' => 0];

        $projection = DB::transaction(function () use ($userId, &$mode, &$counts): AchievementProgressProjection {
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
        }, 3);

        if ($mode !== 'incremental' || array_sum($counts) > 0) {
            Log::info('Achievement metric projection updated.', [
                'user_id' => $userId,
                'mode' => $mode,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ...$counts,
            ]);
        }

        return new AchievementMetricProjectionResult(
            metricValues: $this->integerMetrics($projection->metric_values),
            thresholdReachedAt: $this->thresholdDates($projection->threshold_reached_at),
        );
    }

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

    /**
     * @param  array{reviews:int,cards:int,studySessions:int}  $counts
     * @return array{
     *   currentRun:int,
     *   lastCreatedAt:?CarbonImmutable,
     *   lastCreatedId:?string,
     *   latestReviewedAt:?CarbonImmutable,
     *   latestReviewedId:?string,
     * }
     */
    private function seedReviewAndCardFacts(int $userId, array &$counts): array
    {
        /** @var array<string, array{maximumStability:float,lastReviewedAt:?CarbonImmutable,sourceUpdatedAt?:CarbonImmutable}> $cardFacts */
        $cardFacts = [];
        $currentRun = 0;
        $lastCreatedAt = null;
        $lastCreatedId = null;
        $latestReviewedAt = null;
        $latestReviewedId = null;

        foreach ($this->reviewTimeline($userId) as $event) {
            $counts['reviews']++;
            $cardId = (string) $event->card_id;
            $reviewedAt = CarbonImmutable::instance($event->reviewed_at);
            $successful = $event->rating !== CardReviewRating::Again;
            $currentRun = $successful ? $currentRun + 1 : 0;
            $fact = $cardFacts[$cardId] ?? ['maximumStability' => 0.0, 'lastReviewedAt' => null];
            $fact['maximumStability'] = max(
                $fact['maximumStability'],
                $this->stability($event->scheduler_state_after),
            );
            $fact['lastReviewedAt'] = $reviewedAt;
            $cardFacts[$cardId] = $fact;
            $latestReviewedAt = $reviewedAt;
            $latestReviewedId = (string) $event->id;

            $createdAt = CarbonImmutable::instance($event->created_at);
            if ($this->isReviewCreatedAfterCursor($event, $createdAt, $lastCreatedAt, $lastCreatedId)) {
                $lastCreatedAt = $createdAt;
                $lastCreatedId = (string) $event->id;
            }
        }

        foreach ($this->ownedCards($userId)->orderBy('cards.id')->cursor() as $card) {
            $counts['cards']++;
            $cardId = (string) $card->id;
            $fact = $cardFacts[$cardId] ?? ['maximumStability' => 0.0, 'lastReviewedAt' => null];
            $fact['maximumStability'] = max($fact['maximumStability'], $this->stability($card->scheduler_state));
            $fact['sourceUpdatedAt'] = CarbonImmutable::instance($card->updated_at);
            $cardFacts[$cardId] = $fact;
        }

        $now = now();
        collect($cardFacts)->map(
            static fn (array $fact, string $cardId): array => [
                'card_id' => $cardId,
                'user_id' => $userId,
                'maximum_stability' => $fact['maximumStability'],
                'last_reviewed_at' => $fact['lastReviewedAt'],
                'source_updated_at' => $fact['sourceUpdatedAt'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        )->chunk(500)->each(
            static fn ($rows) => DB::table('achievement_card_projections')->upsert(
                $rows->values()->all(),
                ['card_id'],
                ['maximum_stability', 'last_reviewed_at', 'source_updated_at', 'updated_at'],
            ),
        );

        return [
            'currentRun' => $currentRun,
            'lastCreatedAt' => $lastCreatedAt,
            'lastCreatedId' => $lastCreatedId,
            'latestReviewedAt' => $latestReviewedAt,
            'latestReviewedId' => $latestReviewedId,
        ];
    }

    private function isReviewCreatedAfterCursor(
        CardReviewEvent $event,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $lastCreatedAt,
        ?string $lastCreatedId,
    ): bool {
        if ($lastCreatedAt === null) {
            return true;
        }

        if ($createdAt->gt($lastCreatedAt)) {
            return true;
        }

        if (! $createdAt->equalTo($lastCreatedAt)) {
            return false;
        }

        return strcmp((string) $event->id, (string) $lastCreatedId) > 0;
    }

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
            $fact = $this->studyFact($session);
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
            $fact = $this->studyFact($session);

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
    private function projectNewReviews(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
        Collection $events,
    ): void {
        $metrics = $this->integerMetrics($projection->metric_values);
        $thresholdDates = $this->thresholdDates($projection->threshold_reached_at);
        $cardProjections = AchievementCardProjection::query()
            ->whereIn('card_id', $events->pluck('card_id')->map(static fn ($id): string => (string) $id)->unique())
            ->get()
            ->keyBy(static fn (AchievementCardProjection $fact): string => (string) $fact->card_id);

        foreach ($events as $event) {
            $counts['reviews']++;
            $before = $metrics;
            $reviewedAt = CarbonImmutable::instance($event->reviewed_at);
            $cardId = (string) $event->card_id;
            $metrics[GetAchievementProgressAction::REVIEW_METRIC]++;
            if ($event->rating === CardReviewRating::Again) {
                $projection->current_correct_run = 0;
            } else {
                $projection->current_correct_run++;
                $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC] = max(
                    $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC],
                    $projection->current_correct_run,
                );
            }

            $cardProjection = $cardProjections->get($cardId);
            $previousReviewAt = $cardProjection?->last_reviewed_at;
            if ($event->rating !== CardReviewRating::Again
                && $previousReviewAt instanceof CarbonInterface
                && $previousReviewAt->lte($reviewedAt->subMonthsNoOverflow(6))) {
                $metrics[GetAchievementProgressAction::OLD_FRIEND_METRIC] = 1;
            }

            $cardProjections->put($cardId, $this->updateCardProjection(
                $userId,
                $cardId,
                $this->stability($event->scheduler_state_after),
                $reviewedAt,
                $metrics,
                CarbonImmutable::parse($event->getAttribute('card_source_updated_at')),
                fact: $cardProjection,
            ));
            $thresholdDates = $this->thresholdCrossings->recordAll(
                $before,
                $metrics,
                $reviewedAt,
                $thresholdDates,
            );
            $projection->latest_reviewed_at = $reviewedAt;
            $projection->latest_reviewed_id = (string) $event->id;
        }

        if ($events->isNotEmpty()) {
            $lastCreated = $events->sort(
                static fn ($left, $right): int => [$left->created_at->getTimestampMs(), (string) $left->id]
                    <=> [$right->created_at->getTimestampMs(), (string) $right->id],
            )->last();
            $projection->last_review_created_at = CarbonImmutable::instance($lastCreated->created_at);
            $projection->last_review_id = (string) $lastCreated->id;
            $projection->metric_values = $metrics;
            $projection->threshold_reached_at = $thresholdDates;
            $this->persistCardProjections($cardProjections);
        }
    }

    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectChangedCards(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
    ): void {
        $cards = $this->ownedCards($userId)
            ->leftJoin(
                'achievement_card_projections',
                'achievement_card_projections.card_id',
                '=',
                'cards.id',
            )
            ->where(function (Builder $query): void {
                $query->whereNull('achievement_card_projections.card_id')
                    ->orWhereNull('achievement_card_projections.source_updated_at')
                    ->orWhereColumn('cards.updated_at', '>', 'achievement_card_projections.source_updated_at');
            })
            ->get()
            ->sort(static function (Card $left, Card $right): int {
                $leftAt = CarbonImmutable::instance($left->last_reviewed_at ?? $left->created_at);
                $rightAt = CarbonImmutable::instance($right->last_reviewed_at ?? $right->created_at);
                $dateComparison = $leftAt <=> $rightAt;

                return $dateComparison !== 0
                    ? $dateComparison
                    : strcmp((string) $left->id, (string) $right->id);
            })
            ->values();
        if ($cards->isEmpty()) {
            return;
        }

        $metrics = $this->integerMetrics($projection->metric_values);
        $thresholdDates = $this->thresholdDates($projection->threshold_reached_at);
        $cardProjections = AchievementCardProjection::query()
            ->whereIn('card_id', $cards->pluck('id')->map(static fn ($id): string => (string) $id))
            ->get()
            ->keyBy(static fn (AchievementCardProjection $fact): string => (string) $fact->card_id);
        foreach ($cards as $card) {
            $counts['cards']++;
            $before = $metrics;
            $cardId = (string) $card->id;
            $crossedAt = CarbonImmutable::instance($card->last_reviewed_at ?? $card->created_at);
            $cardProjections->put($cardId, $this->updateCardProjection(
                $userId,
                $cardId,
                $this->stability($card->scheduler_state),
                null,
                $metrics,
                CarbonImmutable::instance($card->updated_at),
                $cardProjections->get($cardId),
            ));
            $thresholdDates = $this->thresholdCrossings->recordAll(
                $before,
                $metrics,
                $crossedAt,
                $thresholdDates,
            );
        }
        $this->persistCardProjections($cardProjections);

        $projection->metric_values = $metrics;
        $projection->threshold_reached_at = $thresholdDates;
    }

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
            $fact = $this->studyFact($session);
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
        $studyMilestones = $this->studyMilestones($userId);
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

    /** @param array<string, int> $metrics */
    private function updateCardProjection(
        int $userId,
        string $cardId,
        float $stability,
        ?CarbonImmutable $reviewedAt,
        array &$metrics,
        ?CarbonImmutable $sourceUpdatedAt = null,
        ?AchievementCardProjection $fact = null,
    ): AchievementCardProjection {
        $previousMaximum = $fact?->maximum_stability ?? 0.0;
        $maximum = max($previousMaximum, $stability);
        if ($maximum > $previousMaximum) {
            foreach ($this->thresholdCrossings->masteryThresholds() as $metricKey => $minimumStability) {
                if ($previousMaximum < $minimumStability && $maximum >= $minimumStability) {
                    $metrics[$metricKey]++;
                }
            }
        }

        $fact ??= new AchievementCardProjection;
        $fact->card_id = $cardId;
        $fact->user_id = $userId;
        $fact->maximum_stability = $maximum;
        $fact->last_reviewed_at = $reviewedAt ?? $fact->last_reviewed_at;
        $fact->source_updated_at = $sourceUpdatedAt ?? $fact->source_updated_at;
        $fact->created_at = $fact->created_at ?? now();
        $fact->updated_at = now();

        return $fact;
    }

    /** @param Collection<int|string, AchievementCardProjection> $facts */
    private function persistCardProjections(Collection $facts): void
    {
        $facts->map(static fn (AchievementCardProjection $fact): array => [
            'card_id' => (string) $fact->card_id,
            'user_id' => $fact->user_id,
            'maximum_stability' => $fact->maximum_stability,
            'last_reviewed_at' => $fact->last_reviewed_at,
            'source_updated_at' => $fact->source_updated_at,
            'created_at' => $fact->created_at,
            'updated_at' => $fact->updated_at,
        ])->values()->chunk(500)->each(
            static fn ($rows) => DB::table('achievement_card_projections')->upsert(
                $rows->all(),
                ['card_id'],
                [
                    'user_id',
                    'maximum_stability',
                    'last_reviewed_at',
                    'source_updated_at',
                    'updated_at',
                ],
            ),
        );
    }

    /** @return Builder<Card> */
    private function ownedCards(int $userId): Builder
    {
        return Card::query()
            ->withTrashed()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('cards.*');
    }

    /** @return Builder<CardReviewEvent> */
    private function newReviewEvents(int $userId, AchievementProgressProjection $projection): Builder
    {
        $query = CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select([
                'card_review_events.*',
                'cards.updated_at as card_source_updated_at',
            ]);

        if ($projection->last_review_created_at === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($projection): void {
            $query->where('card_review_events.created_at', '>', $projection->last_review_created_at)
                ->orWhere(function (Builder $query) use ($projection): void {
                    $query->where('card_review_events.created_at', $projection->last_review_created_at)
                        ->where('card_review_events.id', '>', $projection->last_review_id);
                });
        });
    }

    /** @return LazyCollection<int, CardReviewEvent> */
    private function reviewTimeline(int $userId)
    {
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('card_review_events.*')
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->cursor();
    }

    /** @return array{study_day:string,ended_at:CarbonInterface,category:string,conversation_ms:int,listening_ms:int,daily_audio_episode:?string} */
    private function studyFact(StudyActivitySession $session): array
    {
        $episode = null;
        if ($session->activity === StudyActivityKind::DailyAudio
            && $session->name !== null
            && str_starts_with($session->name, CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX)) {
            $candidate = trim(substr(
                $session->name,
                strlen(CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX),
            ));
            $episode = $candidate === '' ? null : $candidate;
        }

        return [
            'study_day' => $session->ended_at->utc()->toDateString(),
            'ended_at' => $session->ended_at,
            'category' => $session->category->value,
            'conversation_ms' => $session->category === StudyActivityCategory::Conversation
                ? $session->duration_ms
                : 0,
            'listening_ms' => $session->category === StudyActivityCategory::Listen
                ? ($session->audio_playback_ms ?? 0)
                : 0,
            'daily_audio_episode' => $episode,
        ];
    }

    /**
     * @return array{
     *   doubleFeature:int,
     *   doubleFeatureReachedAt:?CarbonImmutable,
     *   repeatDays:int,
     *   repeatReachedAt:array<int, CarbonImmutable>
     * }
     */
    private function studyMilestones(int $userId): array
    {
        $categoriesByDay = [];
        $doubleFeatureReachedAt = null;
        $daysByEpisode = [];
        $repeatReachedAt = [];
        $repeatDays = 0;

        $facts = AchievementStudySessionProjection::query()
            ->where('user_id', $userId)
            ->orderBy('ended_at')
            ->orderBy('study_activity_session_id')
            ->get(['study_day', 'ended_at', 'category', 'daily_audio_episode']);

        foreach ($facts as $fact) {
            $day = $fact->study_day->toDateString();
            $categoriesByDay[$day][$fact->category] = true;
            if ($doubleFeatureReachedAt === null && isset(
                $categoriesByDay[$day][StudyActivityCategory::Listen->value],
                $categoriesByDay[$day][StudyActivityCategory::Conversation->value],
            )) {
                $doubleFeatureReachedAt = CarbonImmutable::instance($fact->ended_at);
            }

            $episode = $fact->daily_audio_episode;
            if (! is_string($episode) || isset($daysByEpisode[$episode][$day])) {
                continue;
            }
            $daysByEpisode[$episode][$day] = true;
            $dayCount = count($daysByEpisode[$episode]);
            $repeatDays = max($repeatDays, $dayCount);
            $repeatReachedAt[$dayCount] ??= CarbonImmutable::instance($fact->ended_at);
        }

        return [
            'doubleFeature' => $doubleFeatureReachedAt === null ? 0 : 1,
            'doubleFeatureReachedAt' => $doubleFeatureReachedAt,
            'repeatDays' => $repeatDays,
            'repeatReachedAt' => $repeatReachedAt,
        ];
    }

    /** @param array<string, mixed>|null $schedulerState */
    private function stability(?array $schedulerState): float
    {
        $stability = $schedulerState['stability'] ?? 0;

        return is_int($stability) || is_float($stability) ? (float) $stability : 0.0;
    }

    /** @param mixed $metrics @return array<string, int> */
    private function integerMetrics(mixed $metrics): array
    {
        return collect(is_array($metrics) ? $metrics : [])->mapWithKeys(
            static fn (mixed $value, mixed $key): array => [(string) $key => (int) $value],
        )->all();
    }

    /** @param mixed $dates @return array<string, array<string, string>> */
    private function thresholdDates(mixed $dates): array
    {
        return collect(is_array($dates) ? $dates : [])->mapWithKeys(
            static fn (mixed $value, mixed $key): array => [
                (string) $key => collect(is_array($value) ? $value : [])->mapWithKeys(
                    static fn (mixed $date, mixed $threshold): array => [(string) $threshold => (string) $date],
                )->all(),
            ],
        )->all();
    }
}
