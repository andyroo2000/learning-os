<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class StartStudyLessonAction
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
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Study lesson');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Study lesson');
        $overview = $this->getStudyOverview->handle(
            userId: $userId,
            timeZone: $timeZone,
            now: $now,
            deckId: $deckId,
            courseId: $courseId,
        );
        $limit = min(
            (int) $overview['lesson_batch_size'],
            (int) $overview['new_count'],
        );

        if ($limit <= 0) {
            return new StartStudySessionResult($overview, collect());
        }

        $query = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->select('cards.*')
            ->where('cards.study_status', CardStudyStatus::New->value);
        $cards = NewCardQueueOrdering::positionedCards($query)
            ->limit($limit)
            ->get();

        return new StartStudySessionResult($overview, $cards);
    }

    /**
     * @return Builder<Card>
     */
    private function ownedActiveCardsQuery(int $userId, ?string $courseId, ?string $deckId): Builder
    {
        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereProgressionAvailable()
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId));
    }
}
