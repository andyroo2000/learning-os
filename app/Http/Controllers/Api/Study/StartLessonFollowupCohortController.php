<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\StartLessonFollowupCohortAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StartStudySessionRequest;
use App\Http\Resources\Study\StudySessionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StartLessonFollowupCohortController extends Controller
{
    public function __invoke(
        StartStudySessionRequest $request,
        string $cohortId,
        StartLessonFollowupCohortAction $startCohort,
    ): JsonResponse {
        return StudySessionResource::make($startCohort->handle(
            userId: AuthenticatedUser::id($request),
            cohortId: $cohortId,
            timeZone: $request->timeZone(),
        ))->response()->setStatusCode(200);
    }
}
