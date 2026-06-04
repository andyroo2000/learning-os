<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StartStudySessionResult;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StartStudySessionAction
{
    public const READY_CARD_LIMIT = 300;

    public function __construct(
        private readonly GetStudySettingsAction $getStudySettings,
    ) {}

    public function handle(int $userId, ?string $timeZone = null, ?Carbon $now = null): StartStudySessionResult
    {
        $now ??= now();
        $resolvedTimeZone = $this->resolveTimeZone($timeZone);
        [$dayStart, $dayEnd] = $this->studyDayWindow($now, $resolvedTimeZone);

        $settings = $this->getStudySettings->handle($userId);
        $introducedToday = $this->countIntroducedToday($userId, $dayStart, $dayEnd);
        $overview = $this->overview(
            userId: $userId,
            now: $now,
            newCardsPerDay: $settings->new_cards_per_day,
            introducedToday: $introducedToday,
        );

        $cards = $overview['due_count'] > 0
            ? $this->dueCards($userId, $now, self::READY_CARD_LIMIT)
            : $this->newCards(
                userId: $userId,
                limit: min(self::READY_CARD_LIMIT, $overview['new_cards_available_today']),
            );

        return new StartStudySessionResult($overview, $cards);
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
            throw new InvalidArgumentException('Study session time_zone must be a valid IANA timezone.');
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

    /**
     * @return array<string, mixed>
     */
    private function overview(int $userId, Carbon $now, int $newCardsPerDay, int $introducedToday): array
    {
        $baseQuery = $this->ownedActiveCardsQuery($userId);
        $dueCount = (clone $baseQuery)
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->where('cards.due_at', '<=', $now)
            ->count('cards.id');
        $newCount = (clone $baseQuery)
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereNotNull('cards.new_queue_position')
            ->count('cards.id');
        $remainingNewCards = max(0, $newCardsPerDay - $introducedToday);

        return [
            'due_count' => $dueCount,
            'failed_count' => (clone $baseQuery)
                ->whereIn('cards.study_status', $this->activeDueStatuses())
                ->whereNotNull('cards.failed_at')
                ->count('cards.id'),
            'new_count' => $newCount,
            'new_cards_per_day' => $newCardsPerDay,
            'new_cards_introduced_today' => $introducedToday,
            'new_cards_available_today' => $dueCount > 0 ? 0 : min($newCount, $remainingNewCards),
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
            'next_due_at' => $this->nextDueAt($userId),
        ];
    }

    /**
     * @return Collection<int, Card>
     */
    private function dueCards(int $userId, Carbon $now, int $limit): Collection
    {
        return $this->ownedActiveCardsQuery($userId)
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->where('cards.due_at', '<=', $now)
            ->orderBy('cards.due_at')
            ->orderBy('cards.id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Card>
     */
    private function newCards(int $userId, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return $this->ownedActiveCardsQuery($userId)
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereNotNull('cards.new_queue_position')
            ->orderBy('cards.new_queue_position')
            ->orderBy('cards.id')
            ->limit($limit)
            ->get();
    }

    private function nextDueAt(int $userId): ?string
    {
        $nextDueAt = $this->ownedActiveCardsQuery($userId)
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->whereNotNull('cards.due_at')
            ->orderBy('cards.due_at')
            ->value('cards.due_at');

        return $nextDueAt === null ? null : Carbon::parse($nextDueAt)->toJSON();
    }

    /**
     * @return Builder<Card>
     */
    private function ownedActiveCardsQuery(int $userId): Builder
    {
        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at');
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
