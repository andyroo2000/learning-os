<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\DeleteDeckResult;

class DeleteDeckAction
{
    /**
     * Callers must resolve the deck with withTrashed() to preserve retry idempotency.
     * Already-trashed decks are treated as successful no-ops.
     */
    public function handle(Deck $deck): DeleteDeckResult
    {
        if ($deck->trashed()) {
            return DeleteDeckResult::unchanged($deck);
        }

        $deck->delete();

        return DeleteDeckResult::deleted($deck);
    }
}
