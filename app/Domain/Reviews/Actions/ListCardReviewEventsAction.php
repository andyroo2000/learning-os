<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCardReviewEventsAction
{
    private const PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<CardReviewEvent>
     */
    public function handle(Card $card): CursorPaginator
    {
        return $card->reviewEvents()
            ->orderByDesc('reviewed_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);
    }
}
