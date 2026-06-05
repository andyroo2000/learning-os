<?php

namespace App\Http\Controllers\Api\Reviews\Concerns;

use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait RespondsToUndoCardReviewEventExceptions
{
    protected function undoExceptionResponse(UndoCardReviewEventException $exception, string $reviewEventId): JsonResponse
    {
        $statusCode = $this->statusCodeForUndoException($exception);

        if ($statusCode >= 500) {
            Log::error('Review undo failed because stored undo state is invalid.', [
                'review_event_id' => $reviewEventId,
                'reason' => $exception->reason(),
            ]);
        }

        return response()->json([
            'message' => $exception->getMessage(),
            'reason' => $exception->reason(),
        ], $statusCode);
    }

    private function statusCodeForUndoException(UndoCardReviewEventException $exception): int
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
