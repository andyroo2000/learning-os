<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class DeleteStudyCardController extends Controller
{
    public function __invoke(string $cardId, DeleteCardAction $deleteCard): Response
    {
        // Preserve ConvoLab retry compatibility: owned soft-deleted cards still return 204.
        $card = Card::withTrashed()
            ->whereClientIdentifier($cardId)
            ->firstOrFail();

        $this->authorize('delete', $card);

        $deleteCard->handle($card);

        return response()->noContent();
    }
}
