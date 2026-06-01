<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Support\Pagination\CursorPagination;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(int $userId, int $perPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginator
    {
        // Defensive for non-HTTP callers; controllers still validate the public API contract.
        $perPage = CursorPagination::clampPageSize($perPage);

        return Card::query()
            ->whereHas('deck', fn ($query) => $query->where('user_id', $userId))
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
