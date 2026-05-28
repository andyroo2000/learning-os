<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\StoreDeckRequest;
use App\Http\Resources\Flashcards\DeckResource;
use Illuminate\Http\JsonResponse;

class StoreDeckController extends Controller
{
    public function __invoke(StoreDeckRequest $request, CreateDeckAction $createDeck): JsonResponse
    {
        $data = $request->validated();

        $deck = $createDeck->handle(CreateDeckData::fromInput(
            userId: $request->user()->id,
            name: $data['name'],
            description: $data['description'] ?? null,
            id: $data['id'] ?? null,
        ));

        return DeckResource::make($deck)
            ->response()
            ->setStatusCode(201);
    }
}
