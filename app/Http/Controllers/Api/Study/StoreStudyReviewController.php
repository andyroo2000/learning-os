<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyReviewRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StoreStudyReviewController extends Controller
{
    public function __invoke(
        StoreStudyReviewRequest $request,
        ReviewCardAction $reviewCard,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();
        // currentOverview is accepted for ConvoLab request compatibility; this adapter recomputes overview below.
        $userId = AuthenticatedUser::id($request);
        $card = $this->ownedActiveCard($data['cardId'], $userId);

        if ($card === null) {
            return response()->json(['message' => 'Study card not found.'], 404);
        }

        // Legacy callers omit a client identity and retain server-time behavior. New callers pair a stable
        // event ID with the logical review time so an ambiguous transport retry can reuse canonical idempotency.
        $reviewedAt = $data['reviewedAt'] ?? Carbon::now();

        try {
            $result = $reviewCard->handle(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: $data['grade'],
                reviewedAt: $reviewedAt,
                durationMs: $request->durationMs($data),
                id: $data['clientReviewId'] ?? null,
            ));
        } catch (CardReviewEventConflictException $exception) {
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            if ($exception->isRetryable()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason' => $exception->reason(),
                ], 503)->header('Retry-After', (string) CardReviewEventConflictException::RETRY_AFTER_SECONDS);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        // Re-fetch through the ownership/deck guard after the action writes scheduler state back to the card row.
        // If a card or deck is deleted during the request, the committed fallback below prevents a retry.
        $card = $this->ownedActiveCard($card->id, $userId);

        if ($card === null) {
            $overview = StudyOverviewCompatibilityResource::make(
                $getStudyOverview->handle(
                    userId: $userId,
                    timeZone: $request->timeZone($data),
                    deckId: $request->deckId(),
                    courseId: $request->courseId(),
                    includeGuidance: false,
                ),
            )->resolve($request);

            // The review is committed, so use 200 to prevent clients from retrying a successful write.
            return response()->json([
                'message' => 'Study card not found after review.',
                'reviewLogId' => $result->reviewEvent->id,
                'committed' => true,
                'cardFetchFailed' => true,
                'card' => null,
                'overview' => $overview,
            ]);
        }

        return response()->json([
            'reviewLogId' => $result->reviewEvent->id,
            // Resolve resources inline because this compatibility response intentionally has no data wrapper.
            'card' => StudyCardSummaryResource::make($card)->resolve($request),
            // ConvoLab clients may send currentOverview; this adapter recomputes to keep counts authoritative.
            'overview' => StudyOverviewCompatibilityResource::make(
                $getStudyOverview->handle(
                    userId: $userId,
                    timeZone: $request->timeZone($data),
                    deckId: $request->deckId(),
                    courseId: $request->courseId(),
                    includeGuidance: false,
                ),
            )->resolve($request),
        ]);
    }

    private function ownedActiveCard(string $cardId, int $userId): ?Card
    {
        return Card::query()
            ->ownedByActiveDeck($userId)
            ->whereClientIdentifier($cardId)
            ->first();
    }
}
