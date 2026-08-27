<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudyListScopeFilter;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\DateTime\ServerTimestamp;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

class GetStudyOverviewAction
{
    private const PAUSE_RECALL_THRESHOLD = 0.80;

    private const EASE_UP_RECALL_THRESHOLD = 0.88;

    private const STEADY_RECALL_THRESHOLD = 0.93;

    private const STRONG_RECALL_THRESHOLD = 0.97;

    private const MINIMUM_TIMED_REVIEW_SAMPLE_SIZE = 10;

    private const READY_HEADROOM_MINUTES = 15;

    private const STRONG_HEADROOM_MINUTES = 30;

    public function __construct(private readonly GetJlptMasteryAction $getJlptMastery) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        int $userId,
        ?string $timeZone = null,
        ?Carbon $now = null,
        ?string $deckId = null,
        ?string $courseId = null,
        bool $includeGuidance = true,
    ): array {
        $now ??= now();
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Study overview');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Study overview');

        $resolvedTimeZone = $this->resolveTimeZone($timeZone);
        [$dayStart, $dayEnd] = $this->studyDayWindow($now, $resolvedTimeZone);
        $cardMetrics = $this->cardMetrics($userId, $courseId, $deckId, $now, $dayStart, $dayEnd);
        $dueCount = $cardMetrics['due_count'];
        $failedDueCount = $cardMetrics['failed_due_count'];
        $newCount = $cardMetrics['new_count'];
        $introducedToday = $cardMetrics['new_cards_introduced_today'];
        $newCardsPerDay = $cardMetrics['new_cards_per_day'];
        $remainingNewCards = max(0, $newCardsPerDay - $introducedToday);
        $overview = [
            'due_count' => $dueCount,
            'failed_count' => $cardMetrics['failed_count'],
            'failed_due_count' => $failedDueCount,
            'new_count' => $newCount,
            'new_cards_per_day' => $newCardsPerDay,
            'lesson_batch_size' => $cardMetrics['lesson_batch_size'],
            'review_time_budget_minutes' => $cardMetrics['review_time_budget_minutes'],
            'new_cards_introduced_today' => $introducedToday,
            'new_cards_available_today' => min($newCount, $remainingNewCards),
            'learning_count' => $cardMetrics['learning_count'],
            'review_count' => $cardMetrics['review_count'],
            'suspended_count' => $cardMetrics['suspended_count'],
            'total_cards' => $cardMetrics['total_cards'],
            'latest_import' => $this->latestImport($userId),
            'next_due_at' => $cardMetrics['next_due_at'],
        ];

        if (! $includeGuidance) {
            return $overview;
        }

        $masterySpread = $this->masterySpread($userId, $courseId, $deckId);
        $overview['mastery_spread'] = $masterySpread;
        $overview['jlpt_mastery'] = $this->getJlptMastery->handle($userId, $courseId, $deckId);
        $overview['learning_readiness'] = $this->learningReadiness(
            userId: $userId,
            courseId: $courseId,
            deckId: $deckId,
            now: $now,
            dueCount: $dueCount + $failedDueCount,
            apprenticeCount: $masterySpread[StudyMasteryLevel::Apprentice->value],
            lessonBatchSize: $cardMetrics['lesson_batch_size'],
            reviewTimeBudgetMinutes: $cardMetrics['review_time_budget_minutes'],
        );

        return $overview;
    }

    private function resolveTimeZone(?string $timeZone): string
    {
        $resolvedTimeZone = trim($timeZone ?? '');

        if ($resolvedTimeZone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($resolvedTimeZone);
        } catch (Exception) {
            throw new InvalidArgumentException('Study time_zone must be a valid IANA timezone.');
        }

        return $resolvedTimeZone;
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function studyDayWindow(Carbon $now, string $timeZone): array
    {
        $localStart = $now->copy()->setTimezone($timeZone)->startOfDay();

        return [
            $localStart->copy()->setTimezone('UTC'),
            $localStart->copy()->addDay()->setTimezone('UTC'),
        ];
    }

    private function latestImport(int $userId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Builder<Card>
     */
    private function ownedActiveCardsQuery(int $userId, ?string $courseId = null, ?string $deckId = null): Builder
    {
        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId));
    }

    /**
     * @return array{
     *     due_count: int,
     *     failed_count: int,
     *     failed_due_count: int,
     *     new_count: int,
     *     new_cards_per_day: int,
     *     lesson_batch_size: int,
     *     review_time_budget_minutes: int,
     *     new_cards_introduced_today: int,
     *     learning_count: int,
     *     review_count: int,
     *     suspended_count: int,
     *     total_cards: int,
     *     next_due_at: string|null,
     * }
     */
    private function cardMetrics(
        int $userId,
        ?string $courseId,
        ?string $deckId,
        Carbon $now,
        Carbon $dayStart,
        Carbon $dayEnd,
    ): array {
        $activeDueStatuses = $this->activeDueStatuses();
        $learningStatuses = $this->learningStatuses();
        $suspendedStatuses = $this->suspendedStatuses();
        $activeDueStatusPlaceholders = $this->statusPlaceholders($activeDueStatuses, 'active due statuses');
        $learningStatusPlaceholders = $this->statusPlaceholders($learningStatuses, 'learning statuses');
        $suspendedStatusPlaceholders = $this->statusPlaceholders($suspendedStatuses, 'suspended statuses');
        $progressionAvailable = '(cards.variant_status IS NULL OR cards.variant_status = ?)';
        $availableVariantStatus = VocabVariantStatus::Available->value;
        $nowFormatted = $now->toDateTimeString();
        $dayStartFormatted = $dayStart->toDateTimeString();
        $dayEndFormatted = $dayEnd->toDateTimeString();
        $row = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            // CASE aggregates keep this portable across SQLite, MySQL, and Postgres.
            // The settings MAX() subquery is a scalar singleton read; study_settings_user_id_unique enforces one row per user.
            ->selectRaw(<<<SQL
                COUNT(cards.id) AS total_cards,
                COALESCE((
                    SELECT MAX(study_settings.new_cards_per_day)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS new_cards_per_day,
                COALESCE((
                    SELECT MAX(study_settings.lesson_batch_size)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS lesson_batch_size,
                COALESCE((
                    SELECT MAX(study_settings.review_time_budget_minutes)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS review_time_budget_minutes,
                COALESCE((
                    SELECT MAX(study_settings.standard_lane_weight)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS standard_lane_weight,
                COALESCE((
                    SELECT MAX(study_settings.lesson_followup_lane_weight)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS lesson_followup_lane_weight,
                COALESCE((
                    SELECT MAX(study_settings.wanikani_lane_weight)
                    FROM study_settings
                    WHERE study_settings.user_id = ?
                ), ?) AS wanikani_lane_weight,
                (
                    SELECT COUNT(introduced_cards.id)
                    FROM cards AS introduced_cards
                    INNER JOIN decks AS introduced_decks ON introduced_decks.id = introduced_cards.deck_id
                    WHERE introduced_decks.user_id = ?
                        AND introduced_decks.deleted_at IS NULL
                        AND introduced_cards.deleted_at IS NULL
                        AND introduced_cards.introduced_at >= ?
                        AND introduced_cards.introduced_at < ?
                ) AS new_cards_introduced_today,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at <= ? AND cards.failed_at IS NULL THEN 1 ELSE 0 END), 0) AS due_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at <= ? AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_due_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND (cards.introduction_available_at IS NULL OR cards.introduction_available_at <= ?) AND cards.study_status = ? AND cards.new_queue_position IS NOT NULL AND (cards.selection_policy IS NULL OR cards.selection_policy = ? OR cards.priority_until IS NULL OR cards.priority_until <= ?) THEN 1 ELSE 0 END), 0) AS standard_new_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND (cards.introduction_available_at IS NULL OR cards.introduction_available_at <= ?) AND cards.study_status = ? AND cards.new_queue_position IS NOT NULL AND cards.selection_policy = ? AND cards.priority_until > ? THEN 1 ELSE 0 END), 0) AS lesson_followup_new_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND (cards.introduction_available_at IS NULL OR cards.introduction_available_at <= ?) AND cards.study_status = ? AND cards.new_queue_position IS NOT NULL AND cards.selection_policy = ? AND cards.priority_until > ? THEN 1 ELSE 0 END), 0) AS wanikani_new_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$learningStatusPlaceholders}) THEN 1 ELSE 0 END), 0) AS learning_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status = ? THEN 1 ELSE 0 END), 0) AS review_count,
                COALESCE(SUM(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$suspendedStatusPlaceholders}) THEN 1 ELSE 0 END), 0) AS suspended_count,
                MIN(CASE WHEN {$progressionAvailable} AND cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at IS NOT NULL THEN cards.due_at ELSE NULL END) AS next_due_at
                SQL, [
                $userId,
                StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
                $userId,
                StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                $userId,
                StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
                $userId,
                StudySettings::DEFAULT_STANDARD_LANE_WEIGHT,
                $userId,
                StudySettings::DEFAULT_LESSON_FOLLOWUP_LANE_WEIGHT,
                $userId,
                StudySettings::DEFAULT_WANIKANI_LANE_WEIGHT,
                $userId, // New-card daily allowance stays user-wide, even when overview counts are course/deck scoped.
                $dayStartFormatted,
                $dayEndFormatted,
                // due_count
                $availableVariantStatus,
                ...$activeDueStatuses,
                $nowFormatted,
                // failed_due_count
                $availableVariantStatus,
                ...$activeDueStatuses,
                $nowFormatted,
                // failed_count
                $availableVariantStatus,
                ...$activeDueStatuses,
                // standard_new_count
                $availableVariantStatus,
                $nowFormatted,
                CardStudyStatus::New->value,
                CardSelectionPolicy::Standard->value,
                $nowFormatted,
                // lesson_followup_new_count
                $availableVariantStatus,
                $nowFormatted,
                CardStudyStatus::New->value,
                CardSelectionPolicy::ReviewSoon->value,
                $nowFormatted,
                // wanikani_new_count
                $availableVariantStatus,
                $nowFormatted,
                CardStudyStatus::New->value,
                CardSelectionPolicy::Sprinkled->value,
                $nowFormatted,
                // learning_count
                $availableVariantStatus,
                ...$learningStatuses,
                // review_count
                $availableVariantStatus,
                CardStudyStatus::Review->value,
                // suspended_count
                $availableVariantStatus,
                ...$suspendedStatuses,
                // next_due_at
                $availableVariantStatus,
                ...$activeDueStatuses,
            ])
            ->first();

        $nextDueAt = $this->aggregateTimestamp($row?->next_due_at, 'next_due_at');

        $newCount = ((int) $row?->standard_lane_weight > 0 ? (int) $row?->standard_new_count : 0)
            + ((int) $row?->lesson_followup_lane_weight > 0 ? (int) $row?->lesson_followup_new_count : 0)
            + ((int) $row?->wanikani_lane_weight > 0 ? (int) $row?->wanikani_new_count : 0);

        return [
            'due_count' => (int) $row?->due_count,
            'failed_count' => (int) $row?->failed_count,
            'failed_due_count' => (int) $row?->failed_due_count,
            'new_count' => $newCount,
            'new_cards_per_day' => (int) $row?->new_cards_per_day,
            'lesson_batch_size' => (int) $row?->lesson_batch_size,
            'review_time_budget_minutes' => (int) $row?->review_time_budget_minutes,
            'new_cards_introduced_today' => (int) $row?->new_cards_introduced_today,
            'learning_count' => (int) $row?->learning_count,
            'review_count' => (int) $row?->review_count,
            'suspended_count' => (int) $row?->suspended_count,
            'total_cards' => (int) $row?->total_cards,
            'next_due_at' => $nextDueAt,
        ];
    }

    /**
     * @return array{apprentice: int, guru: int, master: int, enlightened: int, burned: int}
     */
    private function masterySpread(int $userId, ?string $courseId, ?string $deckId): array
    {
        $stability = $this->schedulerStabilityExpression();
        $row = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->whereProgressionAvailable()
            // Only introduced, active cards contribute to motivational mastery load.
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->selectRaw(<<<SQL
                COALESCE(SUM(CASE WHEN cards.study_status IN (?, ?) OR {$stability} IS NULL OR {$stability} < 7 THEN 1 ELSE 0 END), 0) AS apprentice,
                COALESCE(SUM(CASE WHEN cards.study_status = ? AND {$stability} >= 7 AND {$stability} < 30 THEN 1 ELSE 0 END), 0) AS guru,
                COALESCE(SUM(CASE WHEN cards.study_status = ? AND {$stability} >= 30 AND {$stability} < 90 THEN 1 ELSE 0 END), 0) AS master,
                COALESCE(SUM(CASE WHEN cards.study_status = ? AND {$stability} >= 90 AND {$stability} < 365 THEN 1 ELSE 0 END), 0) AS enlightened,
                COALESCE(SUM(CASE WHEN cards.study_status = ? AND {$stability} >= ? THEN 1 ELSE 0 END), 0) AS burned
                SQL, [
                CardStudyStatus::Learning->value,
                CardStudyStatus::Relearning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Review->value,
                StudyMasteryLevel::BURNED_STABILITY_DAYS,
            ])
            ->first();

        return [
            'apprentice' => (int) $row?->apprentice,
            'guru' => (int) $row?->guru,
            'master' => (int) $row?->master,
            'enlightened' => (int) $row?->enlightened,
            'burned' => (int) $row?->burned,
        ];
    }

    private function schedulerStabilityExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(json_extract(cards.scheduler_state, '$.stability') AS REAL)",
            'pgsql' => "CAST(cards.scheduler_state->>'stability' AS DOUBLE PRECISION)",
            'mysql' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(cards.scheduler_state, '$.stability')) AS DECIMAL(20, 6))",
            default => throw new UnexpectedValueException('Unsupported database driver for study mastery aggregation.'),
        };
    }

    /**
     * @return array{
     *     recommendation: string,
     *     readiness_level: string,
     *     sample_size: int,
     *     sufficient_data: bool,
     *     recent_recall: float|null,
     *     target_recall: float,
     *     due_backlog: int,
     *     apprentice_count: int,
     *     projected_seven_day_reviews: int,
     *     timed_review_sample_size: int,
     *     median_review_duration_seconds: float|null,
     *     projected_daily_review_minutes: int|null,
     *     review_time_budget_minutes: int,
     *     review_time_headroom_minutes: int|null,
     *     suggested_batch_size: int,
     *     display_status: string,
     *     display_summary: string
     * }
     */
    private function learningReadiness(
        int $userId,
        ?string $courseId,
        ?string $deckId,
        Carbon $now,
        int $dueCount,
        int $apprenticeCount,
        int $lessonBatchSize,
        int $reviewTimeBudgetMinutes,
    ): array {
        $targetRecall = 0.9;
        $reviews = CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereNull('cards.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->where('card_review_events.reviewed_at', '>=', $now->copy()->subDays(14))
            ->orderByDesc('card_review_events.reviewed_at')
            ->orderByDesc('card_review_events.id')
            ->limit(100)
            ->get(['card_review_events.rating', 'card_review_events.duration_ms']);
        $sampleSize = $reviews->count();
        $recentRecall = $sampleSize === 0
            ? null
            : round(
                $reviews->reject(
                    fn (CardReviewEvent $review): bool => $review->rating === CardReviewRating::Again,
                )->count() / $sampleSize,
                3,
            );
        $projectedSevenDayReviews = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->whereProgressionAvailable()
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->whereNotNull('cards.due_at')
            ->where('cards.due_at', '<=', $now->copy()->addDays(7))
            ->count('cards.id');
        $timedDurations = $reviews
            ->pluck('duration_ms')
            ->filter(fn (mixed $duration): bool => is_int($duration) && $duration > 0)
            ->sort()
            ->values();
        $timedReviewSampleSize = $timedDurations->count();
        $medianReviewDurationMilliseconds = $timedReviewSampleSize >= self::MINIMUM_TIMED_REVIEW_SAMPLE_SIZE
            ? $this->median($timedDurations->all())
            : null;
        $medianReviewDurationSeconds = $medianReviewDurationMilliseconds === null
            ? null
            : round($medianReviewDurationMilliseconds / 1000, 1);
        $projectedDailyReviewMinutes = $medianReviewDurationMilliseconds === null
            ? null
            : (int) ceil($projectedSevenDayReviews * $medianReviewDurationMilliseconds / 7 / 60_000);
        $reviewTimeHeadroomMinutes = $projectedDailyReviewMinutes === null
            ? null
            : $reviewTimeBudgetMinutes - $projectedDailyReviewMinutes;
        $sufficientData = $sampleSize >= 30;
        // Raw due and Apprentice counts remain visible context, but only measured recall and
        // projected time pressure qualify readiness for an aggressive learner's chosen budget.
        $readinessLevel = match (true) {
            ! $sufficientData || $recentRecall === null => 'baseline',
            $recentRecall < self::PAUSE_RECALL_THRESHOLD => 'pause',
            $recentRecall < self::EASE_UP_RECALL_THRESHOLD => 'ease_up',
            $projectedDailyReviewMinutes !== null && $projectedDailyReviewMinutes > $reviewTimeBudgetMinutes => 'ease_up',
            $recentRecall < self::STEADY_RECALL_THRESHOLD => 'steady',
            $reviewTimeHeadroomMinutes !== null && $reviewTimeHeadroomMinutes < self::READY_HEADROOM_MINUTES => 'steady',
            $recentRecall < self::STRONG_RECALL_THRESHOLD => 'ready',
            $reviewTimeHeadroomMinutes === null => 'ready',
            $reviewTimeHeadroomMinutes < self::STRONG_HEADROOM_MINUTES => 'ready',
            default => 'strong',
        };
        // Preserve the established recommendation values for older clients during a rolling deployment.
        $recommendation = match ($readinessLevel) {
            'pause' => 'pause',
            'ease_up' => 'caution',
            default => 'ready',
        };
        $displayStatus = match ($recommendation) {
            'pause' => 'Reviews first recommended',
            'caution' => 'Add carefully',
            default => 'Ready to learn',
        };
        $displaySummary = $sufficientData && $recentRecall !== null
            ? sprintf(
                'Recent recall is %d%% against a %d%% target. %s %s %s reinforcement, with %s %s projected over seven days.',
                (int) round($recentRecall * 100),
                (int) round($targetRecall * 100),
                number_format($apprenticeCount),
                $apprenticeCount === 1 ? 'Apprentice card' : 'Apprentice cards',
                $apprenticeCount === 1 ? 'needs' : 'need',
                number_format($projectedSevenDayReviews),
                $projectedSevenDayReviews === 1 ? 'review' : 'reviews',
            )
            : sprintf(
                'Building a recommendation from your first 30 answers (%s so far). Current seven-day workload: %s %s.',
                number_format($sampleSize),
                number_format($projectedSevenDayReviews),
                $projectedSevenDayReviews === 1 ? 'review' : 'reviews',
            );

        $configuredBatchSize = min(
            StudySettings::MAX_LESSON_BATCH_SIZE,
            max(StudySettings::MIN_LESSON_BATCH_SIZE, $lessonBatchSize),
        );
        // Baseline and steady learners keep their configured lesson size; only explicit
        // reviews-first/ease-up guidance recommends shrinking the next lesson.
        $suggestedBatchSize = match ($readinessLevel) {
            'pause' => StudySettings::MIN_LESSON_BATCH_SIZE,
            'ease_up' => max(
                StudySettings::MIN_LESSON_BATCH_SIZE,
                (int) ceil($configuredBatchSize / 2),
            ),
            default => $configuredBatchSize,
        };

        return [
            'recommendation' => $recommendation,
            'readiness_level' => $readinessLevel,
            'sample_size' => $sampleSize,
            'sufficient_data' => $sufficientData,
            'recent_recall' => $recentRecall,
            'target_recall' => $targetRecall,
            'due_backlog' => $dueCount,
            'apprentice_count' => $apprenticeCount,
            'projected_seven_day_reviews' => $projectedSevenDayReviews,
            'timed_review_sample_size' => $timedReviewSampleSize,
            'median_review_duration_seconds' => $medianReviewDurationSeconds,
            'projected_daily_review_minutes' => $projectedDailyReviewMinutes,
            'review_time_budget_minutes' => $reviewTimeBudgetMinutes,
            'review_time_headroom_minutes' => $reviewTimeHeadroomMinutes,
            'suggested_batch_size' => $suggestedBatchSize,
            'display_status' => $displayStatus,
            'display_summary' => $displaySummary,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return (float) $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function aggregateTimestamp(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface && ! is_string($value)) {
            throw new UnexpectedValueException("Study overview {$label} aggregate has an unexpected timestamp type.");
        }

        return ServerTimestamp::toJson($value)
            ?? throw new UnexpectedValueException("Study overview {$label} aggregate is not a valid timestamp.");
    }

    /**
     * @return list<string>
     */
    private function activeDueStatuses(): array
    {
        return [
            CardStudyStatus::Learning->value,
            CardStudyStatus::Review->value,
            CardStudyStatus::Relearning->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function learningStatuses(): array
    {
        // Keep this explicit: active due statuses can include non-learning phases such as review.
        return [
            CardStudyStatus::Learning->value,
            CardStudyStatus::Relearning->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function suspendedStatuses(): array
    {
        return [
            CardStudyStatus::Suspended->value,
            CardStudyStatus::Buried->value,
        ];
    }

    /**
     * @param  list<string>  $statuses
     */
    private function statusPlaceholders(array $statuses, string $label): string
    {
        // Defensive guard for raw SQL status groups; `IN ()` would be invalid SQL.
        if ($statuses === []) {
            throw new InvalidArgumentException("Study overview {$label} must include at least one status.");
        }

        return implode(', ', array_fill(0, count($statuses), '?'));
    }
}
