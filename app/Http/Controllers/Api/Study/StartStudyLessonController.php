<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StartStudyLessonRequest;
use App\Http\Resources\Study\StudySessionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StartStudyLessonController extends Controller
{
    public function __invoke(
        StartStudyLessonRequest $request,
        StartStudyLessonAction $startStudyLesson,
    ): JsonResponse {
        $scope = [
            'timeZone' => $request->timeZone(),
            'deckId' => $request->deckId(),
            'courseId' => $request->courseId(),
        ];
        $userId = AuthenticatedUser::id($request);
        $pileId = $request->validated('pileId');
        $result = $pileId === null
            ? $startStudyLesson->handle(...['userId' => $userId, ...$scope])
            : $startStudyLesson->fromPile($userId, $pileId, $scope);

        return StudySessionResource::make($result)->response()->setStatusCode(200);
    }
}
