<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListNewCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        ?string $courseId = null,
        ?string $deckId = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : CanonicalUlid::normalize($courseId);
        $deckId = $deckId === null ? null : CanonicalUlid::normalize($deckId);

        if ($courseId === '') {
            throw new InvalidArgumentException('New card course_id filter must not be blank when provided.');
        }

        if ($deckId === '') {
            throw new InvalidArgumentException('New card deck_id filter must not be blank when provided.');
        }

        return Card::query()
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            ->when($deckId !== null, fn ($query) => $query->where('cards.deck_id', $deckId))
            ->where('cards.study_status', CardStudyStatus::New->value)
            ->whereNotNull('cards.new_queue_position')
            ->orderBy('cards.new_queue_position')
            // id asc is stable for cursor pagination when legacy rows share a queue position.
            ->orderBy('cards.id')
            ->cursorPaginate($pageSize->value());
    }
}
