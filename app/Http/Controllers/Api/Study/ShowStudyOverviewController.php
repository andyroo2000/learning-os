<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowStudyOverviewRequest;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ShowStudyOverviewController extends Controller
{
    public function __invoke(
        ShowStudyOverviewRequest $request,
        GetStudyOverviewAction $getStudyOverview,
    ): JsonResponse {
        $data = $request->validated();

        return StudyOverviewCompatibilityResource::make(
            $getStudyOverview->handle(
                userId: AuthenticatedUser::id($request),
                timeZone: $data['time_zone'] ?? null,
                deckId: $request->deckId(),
                courseId: $request->courseId(),
            ),
        )->response()->setStatusCode(200);
    }
}
