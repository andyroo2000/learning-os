<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StartStudySessionAction
{
    public const READY_CARD_LIMIT = 300;

    public function __construct(
        private readonly GetStudyOverviewAction $getStudyOverview,
    ) {}

    public function handle(int $userId, ?string $timeZone = null, ?Carbon $now = null, ?string $deckId = null): StartStudySessionResult
    {
        $now ??= now();
        $deckId = $deckId === null ? null : CanonicalUlid::normalize($deckId);

        if ($deckId === '') {
            throw new InvalidArgumentException('Study deck_id filter must not be blank when provided.');
        }

        $overview = $this->getStudyOverview->handle(
            userId: $userId,
            timeZone: $timeZone,
            now: $now,
            deckId: $deckId,
        );

        $cards = $overview['due_count'] > 0 || $overview['failed_due_count'] > 0
            ? $this->dueCards($userId, $now, self::READY_CARD_LIMIT, $deckId)
            : $this->newCards(
                userId: $userId,
                limit: min(self::READY_CARD_LIMIT, $overview['new_cards_available_today']),
                deckId: $deckId,
            );

        return new StartStudySessionResult($overview, $cards);
    }

    /**
     * @return Collection<int, Card>
     */
    private function dueCards(int $userId, Carbon $now, int $limit, ?string $deckId): Collection
    {
        return $this->ownedActiveCardsQuery($userId, $deckId)
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
    private function newCards(int $userId, int $limit, ?string $deckId): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return $this->ownedActiveCardsQuery($userId, $deckId)
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereNotNull('cards.new_queue_position')
            ->orderBy('cards.new_queue_position')
            ->orderBy('cards.id')
            ->limit($limit)
            ->get();
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
