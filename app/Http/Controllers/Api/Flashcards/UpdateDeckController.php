<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\UpdateDeckAction;
use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\UpdateDeckRequest;
use App\Http\Resources\Flashcards\DeckResource;
use Illuminate\Http\JsonResponse;

class UpdateDeckController extends Controller
{
    public function __invoke(UpdateDeckRequest $request, Deck $deck, UpdateDeckAction $updateDeck): JsonResponse
    {
        $deck = $updateDeck->handle($deck, UpdateDeckData::fromInput(
            name: $request->validated('name'),
            description: $request->validated('description'),
        ));

        return DeckResource::make($deck)
            ->response();
    }
}
