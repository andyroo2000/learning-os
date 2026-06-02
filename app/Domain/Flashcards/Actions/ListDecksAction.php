<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListDecksAction
{
    /**
     * @return CursorPaginator<Deck>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();

        return Deck::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
