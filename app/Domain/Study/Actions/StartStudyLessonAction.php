<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\StartStudySessionResult;
use App\Domain\Study\Services\SelectNewStudyCards;
use App\Domain\Study\Support\StudyListScopeFilter;
use App\Domain\Study\Support\StudyPileQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class StartStudyLessonAction
{
    public function __construct(
        private readonly GetStudyOverviewAction $getStudyOverview,
        private readonly SelectNewStudyCards $selectNewStudyCards,
    ) {}

    public function handle(
        int $userId,
        ?string $timeZone = null,
        ?Carbon $now = null,
        ?string $deckId = null,
        ?string $courseId = null,
    ): StartStudySessionResult {
        return $this->start($userId, compact('timeZone', 'now', 'deckId', 'courseId'));
    }

    public function fromPile(int $userId, string $pileId, array $scope = []): StartStudySessionResult
    {
        return $this->start($userId, [...$scope, 'pileId' => $pileId]);
    }

    private function start(int $userId, array $input): StartStudySessionResult
    {
        $now = $input['now'] ?? now();
        $timeZone = $input['timeZone'] ?? null;
        $deckId = $input['deckId'] ?? null;
        $courseId = $input['courseId'] ?? null;
        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'Study lesson');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'Study lesson');
        $overview = $this->getStudyOverview->handle(
            userId: $userId,
            timeZone: $timeZone,
            now: $now,
            deckId: $deckId,
            courseId: $courseId,
        );
        $limit = (int) $overview['lesson_batch_size'];

        $query = $this->ownedActiveCardsQuery($userId, $courseId, $deckId)
            ->select('cards.*')
            ->where('cards.study_status', CardStudyStatus::New->value);
        if (isset($input['pileId'])) {
            $cards = StudyPileQueue::select($query, [
                'pile_id' => $input['pileId'], 'user_id' => $userId, 'now' => $now, 'limit' => $limit,
            ]);

            return new StartStudySessionResult($overview, $cards);
        }
        $cards = $this->selectNewStudyCards->handle(
            $query,
            $userId,
            $limit,
            $now,
            $timeZone,
        );

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
