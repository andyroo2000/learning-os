<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDeckCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(
        Deck $deck,
        ?CursorPageSize $pageSize = null,
        CardStudyStatus|string|null $studyStatus = null,
        ?string $q = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $studyStatus = $studyStatus === null ? null : CardStudyStatus::fromFilter($studyStatus);
        $searchPattern = $q === null ? null : CardSearchText::likePattern($q);

        return $deck->cards()
            ->with(['deck:id,user_id,course_id'])
            ->when($studyStatus !== null, fn ($query) => $query->where('study_status', $studyStatus->value))
            ->when($searchPattern !== null, fn ($query) => $query->whereRaw(
                "lower(coalesce(cards.search_text, '')) like ? escape ?",
                [$searchPattern, '\\'],
            ))
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
