<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;

class UndoCardReviewEventController extends Controller
{
    public function __invoke(
        CardReviewEvent $cardReviewEvent,
        UndoCardReviewEventAction $undoCardReviewEvent,
    ): JsonResponse {
        $this->authorize('delete', $cardReviewEvent);

        try {
            $card = $undoCardReviewEvent->handle($cardReviewEvent);
        } catch (UndoCardReviewEventException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->statusCode());
        }

        return CardResource::make($card)
            ->response()
            ->setStatusCode(200);
    }
}
