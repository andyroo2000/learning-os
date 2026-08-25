<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StartStudySessionAction
{
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

        $cards = $this->dueCards($userId, $now, $courseId, $deckId);

        return new StartStudySessionResult($overview, $cards);
    }

    /**
     * @return Collection<int, Card>
     */
    private function dueCards(int $userId, Carbon $now, ?string $courseId, ?string $deckId): Collection
    {
        return $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->select('cards.*')
            ->whereIn('cards.study_status', $this->activeDueStatuses())
            ->where('cards.due_at', '<=', $now)
            ->orderBy('cards.due_at')
            ->orderBy('cards.id')
            ->get();
    }

    /**
     * @return Builder<Card>
     */
    private function ownedActiveCardsQuery(int $userId, ?string $courseId = null, ?string $deckId = null): Builder
    {
        return Card::query()
            // This join enforces deck ownership and excludes cards in soft-deleted decks.
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereProgressionAvailable()
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
