<?php

namespace App\Http\Controllers\Api\Study\Concerns;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Api\Reviews\Concerns\RespondsToUndoCardReviewEventExceptions;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsToStudyReviewUndo
{
    use RespondsToUndoCardReviewEventExceptions;

    protected function undoStudyReview(
        Request $request,
        int $userId,
        string $reviewLogId,
        ?string $timeZone,
        UndoCardReviewEventAction $undoCardReviewEvent,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        // Undo hard-deletes the review event, so a retry after success intentionally resolves as not found.
        $reviewLogId = CanonicalUlid::normalize($reviewLogId);
        $reviewEvent = $this->ownedReviewEvent($reviewLogId, $userId);

        if ($reviewEvent === null) {
            return response()->json(['message' => 'Study review not found.'], 404);
        }

        try {
            $card = $undoCardReviewEvent->handle($reviewEvent);
        } catch (UndoCardReviewEventException $exception) {
            return $this->undoExceptionResponse($exception, $reviewLogId);
        }

        return response()->json([
            'reviewLogId' => $reviewLogId,
            // Resolve resources inline because this compatibility response intentionally has no data wrapper.
            'card' => StudyCardSummaryResource::make($card)->resolve($request),
            // ConvoLab clients may send currentOverview; this adapter recomputes to keep counts authoritative.
            'overview' => StudyOverviewCompatibilityResource::make(
                $getStudyOverview->handle(
                    userId: $userId,
                    timeZone: $timeZone,
                ),
            )->resolve($request),
        ]);
    }

    protected function ownedReviewEvent(string $reviewLogId, int $userId): ?CardReviewEvent
    {
        return CardReviewEvent::query()
            ->ownedByActiveCardDeck($userId)
            ->whereKey($reviewLogId)
            ->first();
    }
}
