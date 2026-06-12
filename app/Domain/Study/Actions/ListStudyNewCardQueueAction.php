<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Domain\Flashcards\Support\NewCardQueueOrdering;
use App\Domain\Study\Support\StudyListScopeFilter;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ListStudyNewCardQueueAction
{
    /**
     * @return array{items: Collection<int, Card>, total: int, limit: int, nextCursor: string|null}
     */
    public function handle(
        int $userId,
        int $cursor = 0,
        int $limit = NewCardQueueLimits::PAGE_SIZE_DEFAULT,
        ?string $q = null,
        ?string $courseId = null,
        ?string $deckId = null,
    ): array {
        if ($cursor < 0) {
            throw new InvalidArgumentException('cursor must be a non-negative integer.');
        }

        if ($limit < 1 || $limit > NewCardQueueLimits::PAGE_SIZE_MAX) {
            throw new InvalidArgumentException(
                'limit must be an integer between 1 and '.NewCardQueueLimits::PAGE_SIZE_MAX.'.',
            );
        }

        $courseId = StudyListScopeFilter::normalizeId($courseId, 'courseId', 'New card queue');
        $deckId = StudyListScopeFilter::normalizeId($deckId, 'deckId', 'New card queue');
        $searchPattern = $q === null ? null : CardSearchText::likePattern($q);
        $query = Card::query()
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->when($searchPattern !== null, fn ($query) => $query->whereRaw(
                "lower(coalesce(cards.search_text, '')) like ? escape ?",
                [$searchPattern, '\\'],
            ));

        $total = (clone $query)->count('cards.id');
        $items = NewCardQueueOrdering::nullPositionsLast($query)
            ->orderBy('cards.created_at')
            ->orderBy('cards.id')
            ->skip($cursor)
            ->take($limit)
            ->get();

        $nextOffset = $cursor + $items->count();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            // nextCursor is an integer offset, named for ConvoLab API compatibility.
            'nextCursor' => $nextOffset < $total ? (string) $nextOffset : null,
        ];
    }
}
