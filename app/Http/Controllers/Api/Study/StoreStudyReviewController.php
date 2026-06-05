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
        $userId = $request->user()->id;
        $card = $this->ownedActiveCard($data['cardId'], $userId);

        if ($card === null) {
            return response()->json(['message' => 'Study card not found.'], 404);
        }

        $reviewedAt = Carbon::now();

        try {
            $result = $reviewCard->handle(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: $data['grade'],
                reviewedAt: $reviewedAt,
                durationMs: $request->durationMs(),
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

        // Re-fetch after the action writes scheduler state back to the card row.
        $card = $this->ownedActiveCard($card->id, $userId);

        if ($card === null) {
            return response()->json([
                'message' => 'Study card not found after review.',
                'reviewLogId' => $result->reviewEvent->id,
            ], 404);
        }

        return response()->json([
            'reviewLogId' => $result->reviewEvent->id,
            'card' => StudyCardSummaryResource::make($card)->resolve($request),
            // ConvoLab clients may send currentOverview; this adapter recomputes to keep counts authoritative.
            'overview' => StudyOverviewCompatibilityResource::make(
                $getStudyOverview->handle(
                    userId: $userId,
                    timeZone: $request->timeZone(),
                ),
            )->resolve($request),
        ]);
    }

    private function ownedActiveCard(string $cardId, int $userId): ?Card
    {
        return Card::query()
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereNull('cards.deleted_at')
            ->where('cards.id', $cardId)
            ->first();
    }
}
