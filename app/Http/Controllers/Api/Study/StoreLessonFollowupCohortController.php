<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CreateLessonFollowupCohortAction;
use App\Domain\Study\Exceptions\LessonFollowupCohortConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreLessonFollowupCohortRequest;
use App\Http\Resources\Study\CardIntroductionCohortResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StoreLessonFollowupCohortController extends Controller
{
    public function __invoke(
        StoreLessonFollowupCohortRequest $request,
        CreateLessonFollowupCohortAction $createCohort,
    ): JsonResponse {
        try {
            $cohort = $createCohort->handle(
                userId: AuthenticatedUser::id($request),
                cohortId: $request->cohortId(),
                cardIds: $request->cardIds(),
                label: $request->label(),
            );
        } catch (LessonFollowupCohortConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return CardIntroductionCohortResource::make($cohort)->response()->setStatusCode(200);
    }
}
