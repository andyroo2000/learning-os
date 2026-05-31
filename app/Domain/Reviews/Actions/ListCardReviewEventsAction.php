<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Pagination\CursorPagination;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCardReviewEventsAction
{
    /**
     * @return CursorPaginator<CardReviewEvent>
     */
    public function handle(Card $card, int $perPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginator
    {
        // Defensive for non-HTTP callers; controllers still validate the public API contract.
        $perPage = CursorPagination::clampPageSize($perPage);

        return $card->reviewEvents()
            ->orderByDesc('reviewed_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
