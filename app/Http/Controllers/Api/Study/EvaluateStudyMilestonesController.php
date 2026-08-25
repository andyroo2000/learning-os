<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\EvaluateStudyMilestonesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyMilestoneCompatibilityResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluateStudyMilestonesController extends Controller
{
    public function __invoke(
        Request $request,
        EvaluateStudyMilestonesAction $evaluateStudyMilestones,
    ): JsonResponse {
        return StudyMilestoneCompatibilityResource::make(
            $evaluateStudyMilestones->handle(AuthenticatedUser::id($request)),
        )->response()->setStatusCode(200);
    }
}
