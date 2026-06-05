<?php

namespace App\Http\Controllers\Api\Reviews;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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
            $statusCode = $this->statusCodeFor($exception);

            if ($statusCode >= 500) {
                Log::error('Review undo failed because stored undo state is invalid.', [
                    'review_event_id' => $cardReviewEvent->id,
                    'reason' => $exception->reason(),
                ]);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $statusCode);
        }

        return CardResource::make($card)
            ->response()
            ->setStatusCode(200);
    }

    private function statusCodeFor(UndoCardReviewEventException $exception): int
    {
        return match ($exception->reason()) {
            UndoCardReviewEventException::REVIEW_EVENT_UNAVAILABLE,
            UndoCardReviewEventException::CARD_UNAVAILABLE => 404,
            UndoCardReviewEventException::NOT_LATEST => 409,
            UndoCardReviewEventException::MISSING_SNAPSHOT,
            UndoCardReviewEventException::INVALID_SNAPSHOT => 500,
            default => 500,
        };
    }
}
