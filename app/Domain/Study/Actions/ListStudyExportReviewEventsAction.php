<?php

namespace App\Domain\Study\Actions;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportReviewEventsAction
{
    /**
     * @return Collection<int, CardReviewEvent>
     */
    public function handle(int $userId): Collection
    {
        return CardReviewEvent::query()
            ->select('card_review_events.*')
            ->selectRaw('cards.deck_id as card_deck_id')
            ->selectRaw('decks.course_id as card_course_id')
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            // Joined models do not apply SoftDeletes scopes, so keep these conventional columns explicit.
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->orderBy('card_review_events.id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
