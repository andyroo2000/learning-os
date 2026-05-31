<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Support\Pagination\CursorPagination;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDecksAction
{
    /**
     * @return CursorPaginator<Deck>
     */
    public function handle(int $userId, int $perPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginator
    {
        // Defensive for non-HTTP callers; controllers still validate the public API contract.
        $perPage = CursorPagination::clampPageSize($perPage);

        return Deck::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
