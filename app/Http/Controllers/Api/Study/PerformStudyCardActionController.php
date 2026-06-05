<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\PerformCardStudyAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\PerformStudyCardActionRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;

class PerformStudyCardActionController extends Controller
{
    public function __invoke(
        PerformStudyCardActionRequest $request,
        string $cardId,
        PerformCardStudyAction $performCardStudyAction,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        // currentOverview is accepted for ConvoLab request compatibility; this adapter recomputes overview below.
        $userId = (int) $request->user()->id;
        $cardId = CanonicalUlid::normalize($cardId);
        $card = $this->ownedActiveCard($cardId, $userId);

        if ($card === null) {
            return response()->json(['message' => 'Study card not found.'], 404);
        }

        $result = $performCardStudyAction->handle(
            card: $card,
            action: $request->action(),
            mode: $request->mode(),
            dueAt: $request->dueAt(),
            timeZone: $request->timeZone(),
        );

        return response()->json([
            // Resolve resources inline because this compatibility response intentionally has no data wrapper.
            'card' => StudyCardSummaryResource::make($result->card)->resolve($request),
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
            ->ownedByActiveDeck($userId)
            ->where('cards.id', $cardId)
            ->first();
    }
}
