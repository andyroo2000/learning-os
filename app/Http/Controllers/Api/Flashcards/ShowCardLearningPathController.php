<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListCardLearningPathAction;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardLearningPathResource;

class ShowCardLearningPathController extends Controller
{
    public function __invoke(Card $card, ListCardLearningPathAction $listLearningPath): CardLearningPathResource
    {
        $this->authorize('view', $card);

        return CardLearningPathResource::make([
            'anchor' => $card,
            'cards' => $listLearningPath->handle($card),
        ]);
    }
}
