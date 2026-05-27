<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\StoreCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class StoreCardController extends Controller
{
    public function __invoke(StoreCardRequest $request, CreateCardAction $createCard): JsonResponse
    {
        $data = $request->validated();

        $card = $createCard->handle(CreateCardData::fromInput(
            deckId: $data['deck_id'],
            frontText: $data['front_text'],
            backText: $data['back_text'],
            id: $data['id'] ?? null,
        ));

        return CardResource::make($card)
            ->response()
            ->setStatusCode(201);
    }
}
