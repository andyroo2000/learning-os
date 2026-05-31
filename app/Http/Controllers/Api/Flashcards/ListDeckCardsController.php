<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ListDeckCardsAction;
use App\Domain\Flashcards\Models\Deck;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ListDeckCardsRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDeckCardsController extends Controller
{
    public function __invoke(ListDeckCardsRequest $request, Deck $deck, ListDeckCardsAction $listDeckCards): AnonymousResourceCollection
    {
        $this->authorize('view', $deck);

        return CardResource::collection($listDeckCards->handle($deck, $request->perPage()));
    }
}
