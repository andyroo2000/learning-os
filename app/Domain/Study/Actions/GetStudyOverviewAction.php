<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
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
        $baseQuery = $this->ownedActiveCardsQuery($userId, $deckId);
        $dueCount = (clone $baseQuery)
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->where('cards.due_at', '<=', $now)
            ->whereNull('cards.failed_at')
            ->count('cards.id');
        $failedDueCount = (clone $baseQuery)
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->where('cards.due_at', '<=', $now)
            ->whereNotNull('cards.failed_at')
            ->count('cards.id');
        $newCount = (clone $baseQuery)
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereNotNull('cards.new_queue_position')
            ->count('cards.id');
        $remainingNewCards = max(0, $settings->new_cards_per_day - $introducedToday);

        return [
            'due_count' => $dueCount,
            'failed_count' => (clone $baseQuery)
                ->whereIn('cards.study_status', $this->activeDueStatuses())
                ->whereNotNull('cards.failed_at')
                ->count('cards.id'),
            'failed_due_count' => $failedDueCount,
            'new_count' => $newCount,
            'new_cards_per_day' => $settings->new_cards_per_day,
            'new_cards_introduced_today' => $introducedToday,
            'new_cards_available_today' => $dueCount > 0 || $failedDueCount > 0
                ? 0
                : min($newCount, $remainingNewCards),
            'learning_count' => (clone $baseQuery)
                ->whereIn('cards.study_status', [
                    CardStudyStatus::Learning->value,
                    CardStudyStatus::Relearning->value,
                ])
                ->count('cards.id'),
            'review_count' => (clone $baseQuery)
                ->where('cards.study_status', CardStudyStatus::Review->value)
                ->count('cards.id'),
            'suspended_count' => (clone $baseQuery)
                ->whereIn('cards.study_status', [
                    CardStudyStatus::Suspended->value,
                    CardStudyStatus::Buried->value,
                ])
                ->count('cards.id'),
            'total_cards' => (clone $baseQuery)->count('cards.id'),
            'next_due_at' => $this->nextDueAt($userId, $deckId),
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

    private function nextDueAt(int $userId, ?string $deckId): ?string
    {
        $nextDueAt = $this->ownedActiveCardsQuery($userId, $deckId)
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->whereNotNull('cards.due_at')
            ->orderBy('cards.due_at')
            ->value('cards.due_at');

        return $nextDueAt === null ? null : Carbon::parse($nextDueAt)->toJSON();
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
