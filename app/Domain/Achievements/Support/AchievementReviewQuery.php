<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Builder;

final class AchievementReviewQuery
{
    /** @return Builder<CardReviewEvent> */
    public static function forUser(int $userId): Builder
    {
        // Lifetime achievements include archived cards/decks. Keep their narrow
        // projection shared so new metrics cannot silently omit a needed column
        // in one of the incremental or historical readers.
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select([
                'card_review_events.id',
                'card_review_events.card_id',
                'card_review_events.rating',
                'card_review_events.reviewed_at',
                'card_review_events.created_at',
                'card_review_events.scheduler_state_after',
            ]);
    }
}
