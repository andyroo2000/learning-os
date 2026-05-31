<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDeckCardsAction
{
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<Card>
     */
    public function handle(Deck $deck, int $perPage = self::MAX_PAGE_SIZE): CursorPaginator
    {
        // Defensive for non-HTTP callers; controllers still validate the public API contract.
        $perPage = min(max($perPage, 1), self::MAX_PAGE_SIZE);

        return $deck->cards()
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
