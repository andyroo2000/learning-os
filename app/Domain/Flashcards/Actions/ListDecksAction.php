<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDecksAction
{
    private const PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<Deck>
     */
    public function handle(int $userId): CursorPaginator
    {
        return Deck::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);
    }
}
