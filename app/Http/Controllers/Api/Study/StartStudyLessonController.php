<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StartStudySessionRequest;
use App\Http\Resources\Study\StudySessionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StartStudyLessonController extends Controller
{
    public function __invoke(
        StartStudySessionRequest $request,
        StartStudyLessonAction $startStudyLesson,
    ): JsonResponse {
        $data = $request->validated();

        return StudySessionResource::make(
            $startStudyLesson->handle(
                userId: AuthenticatedUser::id($request),
                timeZone: $data['time_zone'] ?? null,
                deckId: $request->deckId(),
                courseId: $request->courseId(),
            ),
        )->response()->setStatusCode(200);
    }
}
