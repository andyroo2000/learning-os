<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ListDueCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        ?string $courseId = null,
        ?Carbon $now = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : CanonicalUlid::normalize($courseId);
        $now ??= now();

        if ($courseId === '') {
            throw new InvalidArgumentException('Due card course_id filter must not be blank when provided.');
        }

        return Card::query()
            ->with(['deck:id,user_id,course_id'])
            ->whereIn('study_status', [
                CardStudyStatus::Learning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Relearning->value,
            ])
            ->where('due_at', '<=', $now)
            ->whereHas('deck', fn ($query) => $query
                ->where('user_id', $userId)
                ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId)))
            ->orderBy('due_at')
            // id asc is stable for cursor pagination when several cards share a due timestamp.
            ->orderBy('id')
            ->cursorPaginate($pageSize->value());
    }
}
