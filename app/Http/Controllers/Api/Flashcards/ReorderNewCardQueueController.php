<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\ReorderNewCardQueueAction;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ReorderNewCardQueueRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReorderNewCardQueueController extends Controller
{
    public function __invoke(
        ReorderNewCardQueueRequest $request,
        ReorderNewCardQueueAction $reorderNewCardQueue,
    ): AnonymousResourceCollection {
        $data = $request->validated();

        try {
            $cards = $reorderNewCardQueue->handle(
                userId: (int) $request->user()->id,
                cardIds: $data['card_ids'],
            );
        } catch (CardValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        }

        return CardResource::collection($cards);
    }
}
