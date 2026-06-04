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
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->whereIn('cards.study_status', [
                CardStudyStatus::Learning->value,
                CardStudyStatus::Review->value,
                CardStudyStatus::Relearning->value,
            ])
            ->where('cards.due_at', '<=', $now)
            ->orderBy('cards.due_at')
            // id asc is stable for cursor pagination when several cards share a due timestamp.
            ->orderBy('cards.id')
            ->cursorPaginate($pageSize->value());
    }
}
