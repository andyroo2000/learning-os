<?php

namespace App\Domain\Achievements\Actions;

use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Achievements\Results\AchievementMetricProjectionResult;
use App\Domain\Achievements\Support\AchievementCardProjectionUpdater;
use App\Domain\Achievements\Support\AchievementProjectionValues;
use App\Domain\Achievements\Support\AchievementStudyProjectionUpdater;
use App\Domain\Achievements\Support\AchievementThresholdCrossingTracker;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
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
        private readonly AchievementCardProjectionUpdater $cardProjectionUpdater,
        private readonly AchievementStudyProjectionUpdater $studyProjectionUpdater,
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

            if ($this->studyProjectionUpdater->hasOutOfOrderSession($userId, $projection)) {
                $mode = 'rebuild';

                return $this->rebuild($userId, $projection, $counts);
            }

            $this->projectNewReviews($userId, $projection, $counts, $newReviewEvents);
            $this->projectChangedCards($userId, $projection, $counts);
            $this->studyProjectionUpdater->projectChanged($userId, $projection, $counts);
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
            metricValues: AchievementProjectionValues::integerMetrics($projection->metric_values),
            thresholdReachedAt: AchievementProjectionValues::thresholdDates($projection->threshold_reached_at),
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
        $studyState = $this->studyProjectionUpdater->seed($userId, $counts);

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
                $this->cardProjectionUpdater->schedulerStability($event->scheduler_state_after),
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
            $fact['maximumStability'] = max(
                $fact['maximumStability'],
                $this->cardProjectionUpdater->schedulerStability($card->scheduler_state),
            );
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

    /** @param array{reviews:int,cards:int,studySessions:int} $counts */
    private function projectNewReviews(
        int $userId,
        AchievementProgressProjection $projection,
        array &$counts,
        Collection $events,
    ): void {
        $metrics = AchievementProjectionValues::integerMetrics($projection->metric_values);
        $thresholdDates = AchievementProjectionValues::thresholdDates($projection->threshold_reached_at);
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
            if ($this->isOldFriendReview($event, $previousReviewAt, $reviewedAt)) {
                $metrics[GetAchievementProgressAction::OLD_FRIEND_METRIC] = 1;
            }

            $cardProjections->put($cardId, $this->cardProjectionUpdater->updateFromReview(
                $cardProjection,
                $userId,
                $event,
                $metrics,
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

    private function isOldFriendReview(
        CardReviewEvent $event,
        mixed $previousReviewAt,
        CarbonImmutable $reviewedAt,
    ): bool {
        if ($event->rating === CardReviewRating::Again) {
            return false;
        }

        if (! $previousReviewAt instanceof CarbonInterface) {
            return false;
        }

        return $previousReviewAt->lte($reviewedAt->subMonthsNoOverflow(6));
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

        $metrics = AchievementProjectionValues::integerMetrics($projection->metric_values);
        $thresholdDates = AchievementProjectionValues::thresholdDates($projection->threshold_reached_at);
        $cardProjections = AchievementCardProjection::query()
            ->whereIn('card_id', $cards->pluck('id')->map(static fn ($id): string => (string) $id))
            ->get()
            ->keyBy(static fn (AchievementCardProjection $fact): string => (string) $fact->card_id);
        foreach ($cards as $card) {
            $counts['cards']++;
            $before = $metrics;
            $cardId = (string) $card->id;
            $crossedAt = CarbonImmutable::instance($card->last_reviewed_at ?? $card->created_at);
            $cardProjections->put($cardId, $this->cardProjectionUpdater->updateFromCard(
                $cardProjections->get($cardId),
                $userId,
                $card,
                $metrics,
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
}
