<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;

class DeleteCardAction
{
    /**
     * Callers must resolve the card with withTrashed() to preserve retry idempotency.
     * Already-trashed cards are treated as successful no-ops.
     */
    public function handle(Card $card): void
    {
        if ($card->trashed()) {
            return;
        }

        $card->delete();
    }
}
