<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\DeleteCardResult;

class DeleteCardAction
{
    /**
     * Callers must resolve the card with withTrashed() to preserve retry idempotency.
     * Already-trashed cards are treated as successful no-ops.
     */
    public function handle(Card $card): DeleteCardResult
    {
        if ($card->trashed()) {
            return DeleteCardResult::unchanged($card);
        }

        $card->delete();

        return DeleteCardResult::deleted($card);
    }
}
