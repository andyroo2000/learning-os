<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowStudyOverviewRequest;
use App\Http\Resources\Study\StudyOverviewResource;
use Illuminate\Http\JsonResponse;

class ShowStudyOverviewController extends Controller
{
    public function __invoke(
        ShowStudyOverviewRequest $request,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();

        return StudyOverviewResource::make(
            $getStudyOverview->handle(
                userId: (int) $request->user()->id,
                timeZone: $data['time_zone'] ?? null,
                deckId: $request->deckId(),
            ),
        )->response()->setStatusCode(200);
    }
}
