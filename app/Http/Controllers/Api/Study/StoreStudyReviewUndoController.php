<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Reviews\Actions\UndoCardReviewEventAction;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Api\Study\Concerns\RespondsToStudyReviewUndo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyReviewUndoRequest;
use Illuminate\Http\JsonResponse;

class StoreStudyReviewUndoController extends Controller
{
    use RespondsToStudyReviewUndo;

    public function __invoke(
        StoreStudyReviewUndoRequest $request,
        UndoCardReviewEventAction $undoCardReviewEvent,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        return $this->undoStudyReview(
            request: $request,
            userId: (int) $request->user()->id,
            reviewLogId: $request->reviewLogId(),
            timeZone: $request->timeZone(),
            undoCardReviewEvent: $undoCardReviewEvent,
            getStudyOverview: $getStudyOverview,
        );
    }
}
