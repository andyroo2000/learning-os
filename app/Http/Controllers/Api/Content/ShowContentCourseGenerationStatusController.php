<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentCourseGenerationStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentCourseRequest;
use Illuminate\Http\JsonResponse;

final class ShowContentCourseGenerationStatusController extends Controller
{
    public function __invoke(
        ShowContentCourseRequest $request,
        ShowContentCourseGenerationStatusAction $show,
        string $courseId,
    ): JsonResponse {
        $status = $show->handle(
            $request->contentUserId(),
            $request->convoLabUserId(),
            $courseId,
        );

        return $status === null
            ? response()->json(['message' => 'Course not found'], 404)
            : response()->json($status->toArray());
    }
}
