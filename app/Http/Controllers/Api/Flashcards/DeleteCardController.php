<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class DeleteCardController extends Controller
{
    public function __invoke(string $card, DeleteCardAction $deleteCard): Response
    {
        // Bypass route model binding intentionally: normal binding excludes trashed
        // cards, but DELETE retries for owned soft-deleted cards should stay idempotent.
        $cardModel = Card::withTrashed()->findOrFail($card);

        $this->authorize('delete', $cardModel);

        $deleteCard->handle($cardModel);

        return response()->noContent();
    }
}
