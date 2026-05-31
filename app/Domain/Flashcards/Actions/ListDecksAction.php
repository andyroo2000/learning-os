<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDecksAction
{
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<Deck>
     */
    public function handle(int $userId, int $perPage = self::MAX_PAGE_SIZE): CursorPaginator
    {
        // Defensive for non-HTTP callers; controllers still validate the public API contract.
        $perPage = min(max($perPage, 1), self::MAX_PAGE_SIZE);

        return Deck::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
