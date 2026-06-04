<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null, ?string $courseId = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : trim($courseId);

        if ($courseId === '') {
            throw new InvalidArgumentException('Card course_id filter must not be blank when provided.');
        }

        return Card::query()
            ->whereHas('deck', fn ($query) => $query
                ->where('user_id', $userId)
                ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId)))
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
