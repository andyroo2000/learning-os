<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Deck;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class DeleteDeckController extends Controller
{
    public function __invoke(string $deck, DeleteDeckAction $deleteDeck): Response
    {
        // Bypass route model binding intentionally: normal binding excludes trashed
        // decks, but DELETE retries for owned soft-deleted decks should stay idempotent.
        $deckModel = Deck::withTrashed()->findOrFail($deck);

        $this->authorize('delete', $deckModel);

        $deleteDeck->handle($deckModel);

        return response()->noContent();
    }
}
