<?php

namespace App\Http\Controllers\Api\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\StoreCardRequest;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StoreCardController extends Controller
{
    public function __invoke(StoreCardRequest $request, CreateCardAction $createCard): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $request->user()->id;

        try {
            $result = $createCard->handle(CreateCardData::fromInput(
                userId: $userId,
                deckId: $data['deck_id'],
                frontText: $data['front_text'],
                backText: $data['back_text'],
                cardType: $data['card_type'] ?? null,
                promptJson: $data['prompt_json'] ?? null,
                answerJson: $data['answer_json'] ?? null,
                id: $data['id'] ?? null,
            ));
        } catch (CardConflictException $exception) {
            // These conflict exception messages are deliberately user-facing.
            // Hide cross-user IDs before returning same-user deletion details.
            if (! $exception->isOwnedBy($userId)) {
                // Keep this generic so guessed card IDs do not leak cross-user existence.
                return response()->json([
                    'message' => 'Not Found',
                ], 404);
            }

            if ($exception->isDeleted()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason' => $exception->reason(),
                ], 410);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        } catch (CardValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => [$exception->getMessage()],
            ]);
        }

        return CardResource::make($result->card)
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }
}
