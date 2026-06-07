<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class GetStudyOverviewAction
{
    public function __construct(
        private readonly GetStudySettingsAction $getStudySettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $userId, ?string $timeZone = null, ?Carbon $now = null, ?string $deckId = null): array
    {
        $now ??= now();
        $deckId = $deckId === null ? null : CanonicalUlid::normalize($deckId);

        if ($deckId === '') {
            throw new InvalidArgumentException('Study deck_id filter must not be blank when provided.');
        }

        $resolvedTimeZone = $this->resolveTimeZone($timeZone);
        [$dayStart, $dayEnd] = $this->studyDayWindow($now, $resolvedTimeZone);
        $settings = $this->getStudySettings->handle($userId);
        $cardMetrics = $this->cardMetrics($userId, $deckId, $now, $dayStart, $dayEnd);
        $dueCount = $cardMetrics['due_count'];
        $failedDueCount = $cardMetrics['failed_due_count'];
        $newCount = $cardMetrics['new_count'];
        $introducedToday = $cardMetrics['new_cards_introduced_today'];
        $remainingNewCards = max(0, $settings->new_cards_per_day - $introducedToday);

        return [
            'due_count' => $dueCount,
            'failed_count' => $cardMetrics['failed_count'],
            'failed_due_count' => $failedDueCount,
            'new_count' => $newCount,
            'new_cards_per_day' => $settings->new_cards_per_day,
            'new_cards_introduced_today' => $introducedToday,
            'new_cards_available_today' => $dueCount > 0 || $failedDueCount > 0
                ? 0
                : min($newCount, $remainingNewCards),
            'learning_count' => $cardMetrics['learning_count'],
            'review_count' => $cardMetrics['review_count'],
            'suspended_count' => $cardMetrics['suspended_count'],
            'total_cards' => $cardMetrics['total_cards'],
            'latest_import' => $this->latestImport($userId),
            'next_due_at' => $cardMetrics['next_due_at'],
        ];
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
    private function ownedActiveCardsQuery(int $userId, ?string $deckId = null): Builder
    {
        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId));
    }

    /**
     * @return array{
     *     due_count: int,
     *     failed_count: int,
     *     failed_due_count: int,
     *     new_count: int,
     *     new_cards_introduced_today: int,
     *     learning_count: int,
     *     review_count: int,
     *     suspended_count: int,
     *     total_cards: int,
     *     next_due_at: string|null,
     * }
     */
    private function cardMetrics(int $userId, ?string $deckId, Carbon $now, Carbon $dayStart, Carbon $dayEnd): array
    {
        $activeDueStatuses = $this->activeDueStatuses();
        $learningStatuses = $this->learningStatuses();
        $suspendedStatuses = $this->suspendedStatuses();
        $activeDueStatusPlaceholders = $this->statusPlaceholders($activeDueStatuses, 'active due statuses');
        $learningStatusPlaceholders = $this->statusPlaceholders($learningStatuses, 'learning statuses');
        $suspendedStatusPlaceholders = $this->statusPlaceholders($suspendedStatuses, 'suspended statuses');
        $nowFormatted = $now->toDateTimeString();
        $dayStartFormatted = $dayStart->toDateTimeString();
        $dayEndFormatted = $dayEnd->toDateTimeString();
        $row = $this->ownedActiveCardsQuery($userId, $deckId)
            // CASE aggregates keep this portable across SQLite, MySQL, and Postgres.
            ->selectRaw(<<<SQL
                COUNT(cards.id) AS total_cards,
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
                COALESCE(SUM(CASE WHEN cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at <= ? AND cards.failed_at IS NULL THEN 1 ELSE 0 END), 0) AS due_count,
                COALESCE(SUM(CASE WHEN cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at <= ? AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_due_count,
                COALESCE(SUM(CASE WHEN cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN cards.study_status = ? AND cards.new_queue_position IS NOT NULL THEN 1 ELSE 0 END), 0) AS new_count,
                COALESCE(SUM(CASE WHEN cards.study_status IN ({$learningStatusPlaceholders}) THEN 1 ELSE 0 END), 0) AS learning_count,
                COALESCE(SUM(CASE WHEN cards.study_status = ? THEN 1 ELSE 0 END), 0) AS review_count,
                COALESCE(SUM(CASE WHEN cards.study_status IN ({$suspendedStatusPlaceholders}) THEN 1 ELSE 0 END), 0) AS suspended_count,
                MIN(CASE WHEN cards.study_status IN ({$activeDueStatusPlaceholders}) AND cards.due_at IS NOT NULL THEN cards.due_at ELSE NULL END) AS next_due_at
                SQL, [
                // new_cards_introduced_today stays user-wide, even when overview counts are deck-scoped.
                $userId,
                $dayStartFormatted,
                $dayEndFormatted,
                // due_count
                ...$activeDueStatuses,
                $nowFormatted,
                // failed_due_count
                ...$activeDueStatuses,
                $nowFormatted,
                // failed_count
                ...$activeDueStatuses,
                // new_count
                CardStudyStatus::New->value,
                // learning_count
                ...$learningStatuses,
                // review_count
                CardStudyStatus::Review->value,
                // suspended_count
                ...$suspendedStatuses,
                // next_due_at
                ...$activeDueStatuses,
            ])
            ->first();

        $nextDueAt = $row?->next_due_at;

        return [
            'due_count' => (int) $row?->due_count,
            'failed_count' => (int) $row?->failed_count,
            'failed_due_count' => (int) $row?->failed_due_count,
            'new_count' => (int) $row?->new_count,
            'new_cards_introduced_today' => (int) $row?->new_cards_introduced_today,
            'learning_count' => (int) $row?->learning_count,
            'review_count' => (int) $row?->review_count,
            'suspended_count' => (int) $row?->suspended_count,
            'total_cards' => (int) $row?->total_cards,
            'next_due_at' => $nextDueAt === null ? null : Carbon::parse($nextDueAt)->toJSON(),
        ];
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
