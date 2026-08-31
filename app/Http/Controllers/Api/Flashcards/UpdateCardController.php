<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Exceptions\CardContentRevisionConflictException;
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

        try {
            $result = $updateCard->handle($card, UpdateCardData::fromInput(
                frontText: $data['front_text'],
                backText: $data['back_text'],
                cardType: $data['card_type'] ?? null,
                hasPromptJson: array_key_exists('prompt_json', $data),
                promptJson: $data['prompt_json'] ?? null,
                hasAnswerJson: array_key_exists('answer_json', $data),
                answerJson: $data['answer_json'] ?? null,
                // Variant accessors apply presence checks, enum coercion, and UTC normalization.
                hasVariantGroupId: $request->hasVariantGroupId(),
                variantGroupId: $request->variantGroupId(),
                hasVariantSentenceId: $request->hasVariantSentenceId(),
                variantSentenceId: $request->variantSentenceId(),
                hasVariantKind: $request->hasVariantKind(),
                variantKind: $request->variantKind(),
                hasVariantStage: $request->hasVariantStage(),
                variantStage: $request->variantStage(),
                hasVariantStatus: $request->hasVariantStatus(),
                variantStatus: $request->variantStatus(),
                hasVariantUnlockedAt: $request->hasVariantUnlockedAt(),
                variantUnlockedAt: $request->variantUnlockedAt(),
                expectedContentRevision: $request->expectedContentRevision(),
            ));
        } catch (CardContentRevisionConflictException $exception) {
            return response()->json([
                'code' => CardContentRevisionConflictException::CODE,
                'message' => $exception->getMessage(),
                'data' => CardResource::make($exception->card)->resolve($request),
            ], 409);
        }

        return CardResource::make($result->card)
            ->response();
    }
}
