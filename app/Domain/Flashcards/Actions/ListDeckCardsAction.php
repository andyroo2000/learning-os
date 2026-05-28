<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDeckCardsAction
{
    private const PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<Card>
     */
    public function handle(Deck $deck): CursorPaginator
    {
        return $deck->cards()
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);
    }
}
