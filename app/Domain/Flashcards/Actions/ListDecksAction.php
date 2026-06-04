<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListDecksAction
{
    /**
     * @return CursorPaginator<Deck>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null, ?string $courseId = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : trim($courseId);

        if ($courseId === '') {
            throw new InvalidArgumentException('Deck course_id filter must not be blank when provided.');
        }

        return Deck::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
