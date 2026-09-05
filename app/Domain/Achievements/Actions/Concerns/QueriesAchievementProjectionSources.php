<?php

namespace App\Domain\Achievements\Actions\Concerns;

use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

trait QueriesAchievementProjectionSources
{
    /** @return Builder<Card> */
    private function ownedCards(int $userId): Builder
    {
        return Card::query()
            ->withTrashed()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('cards.*');
    }

    /** @return Builder<CardReviewEvent> */
    private function newReviewEvents(int $userId, AchievementProgressProjection $projection): Builder
    {
        $query = CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select([
                'card_review_events.*',
                'cards.updated_at as card_source_updated_at',
            ]);

        if ($projection->last_review_created_at === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($projection): void {
            $query->where('card_review_events.created_at', '>', $projection->last_review_created_at)
                ->orWhere(function (Builder $query) use ($projection): void {
                    $query->where('card_review_events.created_at', $projection->last_review_created_at)
                        ->where('card_review_events.id', '>', $projection->last_review_id);
                });
        });
    }

    /** @return LazyCollection<int, CardReviewEvent> */
    private function reviewTimeline(int $userId): LazyCollection
    {
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->select('card_review_events.*')
            ->orderBy('card_review_events.reviewed_at')
            ->orderBy('card_review_events.id')
            ->cursor();
    }
}
