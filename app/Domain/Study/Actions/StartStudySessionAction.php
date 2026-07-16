<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

class StartStudySessionAction
{
    public const READY_CARD_LIMIT = 300;

    public function __construct(
        private readonly GetStudyOverviewAction $getStudyOverview,
    ) {}

    public function handle(
        int $userId,
        ?string $timeZone = null,
        ?Carbon $now = null,
        ?string $deckId = null,
        ?string $courseId = null,
    ): StartStudySessionResult {
        $now ??= now();
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Study session');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Study session');

        $overview = $this->getStudyOverview->handle(
            userId: $userId,
            timeZone: $timeZone,
            now: $now,
            deckId: $deckId,
            courseId: $courseId,
        );

        $this->assertOverviewKeys($overview, ['due_count', 'failed_due_count']);

        $hasReadyBacklog = $overview['due_count'] > 0 || $overview['failed_due_count'] > 0;

        $cards = $hasReadyBacklog
            ? $this->dueCards($userId, $now, self::READY_CARD_LIMIT, $courseId, $deckId)
            : $this->newCards(
                userId: $userId,
                limit: min(self::READY_CARD_LIMIT, $overview['new_cards_available_today']),
                courseId: $courseId,
                deckId: $deckId,
            );

        return new StartStudySessionResult($overview, $cards);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  list<string>  $keys
     */
    private function assertOverviewKeys(array $overview, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $overview)) {
                throw new LogicException("Study overview is missing {$key}.");
            }
        }
    }

    /**
     * @return Collection<int, Card>
     */
    private function dueCards(int $userId, Carbon $now, int $limit, ?string $courseId, ?string $deckId): Collection
    {
        return $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->select('cards.*')
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
    private function newCards(int $userId, int $limit, ?string $courseId, ?string $deckId): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        $query = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->select('cards.*')
            ->where('cards.study_status', CardStudyStatus::New->value);

        return NewCardQueueOrdering::positionedCards($query)
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<Card>
     */
    private function ownedActiveCardsQuery(int $userId, ?string $courseId = null, ?string $deckId = null): Builder
    {
        return Card::query()
            // This join enforces ownership/soft deletes. Session card queries also project decks.course_id.
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
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
