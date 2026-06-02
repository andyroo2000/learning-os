<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Reviews\Models\CardReviewEvent;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListReviewEventsAction
{
    /**
     * @return CursorPaginator<CardReviewEvent>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromMaxPageSize();

        return CardReviewEvent::query()
            ->select('card_review_events.*')
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            // Joined models do not apply SoftDeletes scopes, so keep these conventional columns explicit.
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->orderByDesc('card_review_events.reviewed_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('card_review_events.id')
            ->cursorPaginate($pageSize->value());
    }
}
