<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Reviews\Exceptions\UndoCardReviewEventException;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Api\Reviews\Concerns\RespondsToUndoCardReviewEventExceptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UndoStudyReviewRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;

class UndoStudyReviewController extends Controller
{
    use RespondsToUndoCardReviewEventExceptions;

    public function __invoke(
        UndoStudyReviewRequest $request,
        string $reviewLogId,
        UndoCardReviewEventAction $undoCardReviewEvent,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();
        // currentOverview is accepted for ConvoLab request compatibility; this adapter recomputes overview below.
        $reviewLogId = CanonicalUlid::normalize($reviewLogId);
        $userId = (int) $request->user()->id;
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
                    timeZone: $request->timeZone($data),
                ),
            )->resolve($request),
        ]);
    }

    private function ownedReviewEvent(string $reviewLogId, int $userId): ?CardReviewEvent
    {
        return CardReviewEvent::query()
            ->ownedByActiveCardDeck($userId)
            ->whereKey($reviewLogId)
            ->first();
    }
}
