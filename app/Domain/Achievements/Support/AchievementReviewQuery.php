<?php

namespace App\Domain\Achievements\Support;

use App\Domain\Achievements\Values\AchievementReviewFact;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use stdClass;

final class AchievementReviewQuery
{
    /** @return LazyCollection<int, AchievementReviewFact> */
    public static function timeline(int $userId): LazyCollection
    {
        // Full rebuilds scan tens of thousands of events. Stream immutable facts
        // and decode each field once instead of constructing writable models.
        return self::forUser($userId)
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->toBase()
            ->cursor()
            ->map(static fn (stdClass $row): AchievementReviewFact => new AchievementReviewFact($row));
    }

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
