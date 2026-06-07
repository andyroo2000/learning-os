<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyCardRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreStudyCardController extends Controller
{
    public function __invoke(
        StoreStudyCardRequest $request,
        ResolveManualStudyDeckAction $resolveManualStudyDeck,
        CreateCardAction $createCard,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        try {
            $result = DB::transaction(function () use ($request, $resolveManualStudyDeck, $createCard, $userId) {
                $deck = $resolveManualStudyDeck->handle($userId);

                return $createCard->handle(CreateCardData::fromInput(
                    userId: $userId,
                    deckId: $deck->id,
                    frontText: $request->frontText(),
                    backText: $request->backText(),
                    cardType: $request->cardType(),
                    promptJson: $request->promptPayload(),
                    answerJson: $request->answerPayload(),
                    id: $request->id(),
                ));
            });
        } catch (CardConflictException $exception) {
            // Hide cross-user IDs before returning same-user deletion details.
            if (! $exception->isOwnedBy($userId)) {
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

        // Resolve manually to preserve ConvoLab's unwrapped compatibility response shape.
        return response()->json(
            StudyCardSummaryResource::make($result->card)->resolve($request),
            $result->wasCreated ? 201 : 200,
        );
    }
}
