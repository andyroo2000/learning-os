<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Enums\StudyMasteryLevel;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudyListScopeFilter;
use App\Domain\Study\Support\StudyOverviewCardsQuery;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\DateTime\ServerTimestamp;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

class GetStudyOverviewAction
{
    public function __construct(
        private readonly GetJlptMasteryAction $getJlptMastery,
        private readonly GetStudyLearningReadinessAction $getStudyLearningReadiness,
        private readonly StudyOverviewCardsQuery $cardsQuery,
    ) {}

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
        $overview['learning_readiness'] = $this->getStudyLearningReadiness->handle(
            new StudyLearningReadinessInput(
                [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'deck_id' => $deckId,
                    'now' => $now,
                ],
                [
                    'due_count' => $dueCount + $failedDueCount,
                    'apprentice_count' => $masterySpread[StudyMasteryLevel::Apprentice->value],
                    'lesson_batch_size' => $cardMetrics['lesson_batch_size'],
                    'review_time_budget_minutes' => $cardMetrics['review_time_budget_minutes'],
                ],
            ),
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
        $activeDueStatuses = $this->cardsQuery->activeDueStatuses();
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
        $row = $this->cardsQuery->forUser($userId, $courseId, $deckId)
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
        $row = $this->cardsQuery->forUser($userId, $courseId, $deckId)
            ->whereProgressionAvailable()
            // Only introduced, active cards contribute to motivational mastery load.
            ->whereIn('cards.study_status', $this->cardsQuery->activeDueStatuses())
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
