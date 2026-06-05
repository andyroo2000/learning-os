<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\UpdateCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class UpdateCardController extends Controller
{
    public function __invoke(UpdateCardRequest $request, Card $card, UpdateCardAction $updateCard): JsonResponse
    {
        $data = $request->validated();

        $result = $updateCard->handle($card, UpdateCardData::fromInput(
            frontText: $data['front_text'],
            backText: $data['back_text'],
            cardType: $data['card_type'] ?? null,
            hasPromptJson: array_key_exists('prompt_json', $data),
            promptJson: $data['prompt_json'] ?? null,
            hasAnswerJson: array_key_exists('answer_json', $data),
            answerJson: $data['answer_json'] ?? null,
        ));

        return CardResource::make($result->card)
            ->response();
    }
}
