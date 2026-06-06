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
        // The daily introduction limit is user-wide, even when overview counts are deck-scoped.
        $introducedToday = $this->countIntroducedToday($userId, $dayStart, $dayEnd);
        $cardMetrics = $this->cardMetrics($userId, $deckId, $now);
        $dueCount = $cardMetrics['due_count'];
        $failedDueCount = $cardMetrics['failed_due_count'];
        $newCount = $cardMetrics['new_count'];
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

    private function countIntroducedToday(int $userId, Carbon $dayStart, Carbon $dayEnd): int
    {
        return (clone $this->ownedActiveCardsQuery($userId))
            ->where('cards.introduced_at', '>=', $dayStart)
            ->where('cards.introduced_at', '<', $dayEnd)
            ->count('cards.id');
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
     *     learning_count: int,
     *     review_count: int,
     *     suspended_count: int,
     *     total_cards: int,
     *     next_due_at: string|null,
     * }
     */
    private function cardMetrics(int $userId, ?string $deckId, Carbon $now): array
    {
        $activeDueStatuses = $this->activeDueStatuses();
        $row = $this->ownedActiveCardsQuery($userId, $deckId)
            // CASE aggregates keep this portable across SQLite, MySQL, and Postgres.
            ->selectRaw(<<<'SQL'
                COUNT(cards.id) AS total_cards,
                SUM(CASE WHEN cards.study_status IN (?, ?, ?) AND cards.due_at <= ? AND cards.failed_at IS NULL THEN 1 ELSE 0 END) AS due_count,
                SUM(CASE WHEN cards.study_status IN (?, ?, ?) AND cards.due_at <= ? AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END) AS failed_due_count,
                SUM(CASE WHEN cards.study_status IN (?, ?, ?) AND cards.failed_at IS NOT NULL THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN cards.study_status = ? AND cards.new_queue_position IS NOT NULL THEN 1 ELSE 0 END) AS new_count,
                SUM(CASE WHEN cards.study_status IN (?, ?) THEN 1 ELSE 0 END) AS learning_count,
                SUM(CASE WHEN cards.study_status = ? THEN 1 ELSE 0 END) AS review_count,
                SUM(CASE WHEN cards.study_status IN (?, ?) THEN 1 ELSE 0 END) AS suspended_count,
                MIN(CASE WHEN cards.study_status IN (?, ?, ?) AND cards.due_at IS NOT NULL THEN cards.due_at ELSE NULL END) AS next_due_at
                SQL, [
                ...$activeDueStatuses,
                $now,
                ...$activeDueStatuses,
                $now,
                ...$activeDueStatuses,
                CardStudyStatus::New->value,
                CardStudyStatus::Learning->value,
                CardStudyStatus::Relearning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Suspended->value,
                CardStudyStatus::Buried->value,
                ...$activeDueStatuses,
            ])
            ->first();

        $nextDueAt = $row?->next_due_at;

        return [
            'due_count' => (int) $row?->due_count,
            'failed_count' => (int) $row?->failed_count,
            'failed_due_count' => (int) $row?->failed_due_count,
            'new_count' => (int) $row?->new_count,
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
}
