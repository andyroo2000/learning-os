<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Api\Study\Concerns\RespondsToStudyReviewUndo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UndoStudyReviewRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class UndoStudyReviewController extends Controller
{
    use RespondsToStudyReviewUndo;

    public function __invoke(
        UndoStudyReviewRequest $request,
        string $reviewLogId,
        UndoCardReviewEventAction $undoCardReviewEvent,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        return $this->undoStudyReview(
            request: $request,
            userId: AuthenticatedUser::id($request),
            reviewLogId: $reviewLogId,
            timeZone: $request->timeZone(),
            deckId: $request->deckId(),
            courseId: $request->courseId(),
            undoCardReviewEvent: $undoCardReviewEvent,
            getStudyOverview: $getStudyOverview,
        );
    }
}
