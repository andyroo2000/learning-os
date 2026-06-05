<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Flashcards\Actions\PerformCardStudyAction;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\PerformStudyCardActionRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use Illuminate\Http\JsonResponse;

class PerformStudyCardActionController extends Controller
{
    public function __invoke(
        PerformStudyCardActionRequest $request,
        PerformCardStudyAction $performCardStudyAction,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $userId = (int) $request->user()->id;

        $result = $performCardStudyAction->handle(
            card: $request->studyCard(),
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
}
