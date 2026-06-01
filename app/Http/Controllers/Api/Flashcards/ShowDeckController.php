<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\DeckResource;

class ShowDeckController extends Controller
{
    public function __invoke(Deck $deck): DeckResource
    {
        $this->authorize('view', $deck);

        return DeckResource::make($deck);
    }
}
