<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Support\Collection;

final class ListCardLearningPathAction
{
    /** @return Collection<int, Card> */
    public function handle(Card $card): Collection
    {
        if (! is_string($card->variant_group_id) || trim($card->variant_group_id) === '') {
            return collect();
        }

        return Card::query()
            ->select('cards.*')
            ->with(['deck:id,user_id,course_id'])
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $card->ownerUserId())
            ->whereNull('decks.deleted_at')
            ->where('cards.variant_group_id', $card->variant_group_id)
            ->orderByRaw('CASE WHEN cards.variant_stage IS NULL THEN 1 ELSE 0 END')
            ->orderBy('cards.variant_stage')
            ->orderByRaw('LOWER(cards.id)')
            ->orderBy('cards.id')
            ->get()
            ->values();
    }
}
