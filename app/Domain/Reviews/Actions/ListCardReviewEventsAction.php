<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCardReviewEventsAction
{
    /**
     * @return CursorPaginator<CardReviewEvent>
     */
    public function handle(Card $card, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        return $card->reviewEvents()
            ->orderByDesc('reviewed_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
