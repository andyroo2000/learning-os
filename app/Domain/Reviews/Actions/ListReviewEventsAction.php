<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListReviewEventsAction
{
    /**
     * @param  string|null  $courseId  Trimmed when provided; non-blank and caller-validated as needed.
     * @return CursorPaginator<CardReviewEvent>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null, ?string $courseId = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        // Keep direct action callers aligned with FormRequest-normalized API input.
        $courseId = $courseId === null ? null : CanonicalUlid::normalize($courseId);

        if ($courseId === '') {
            throw new InvalidArgumentException('Review event course_id filter must not be blank when provided.');
        }

        return CardReviewEvent::query()
            ->select('card_review_events.*')
            ->selectRaw('cards.deck_id as card_deck_id')
            ->selectRaw('decks.course_id as card_course_id')
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('decks.course_id', $courseId))
            // Joined models do not apply SoftDeletes scopes, so keep these conventional columns explicit.
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->orderByDesc('card_review_events.reviewed_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('card_review_events.id')
            ->cursorPaginate($pageSize->value());
    }
}
