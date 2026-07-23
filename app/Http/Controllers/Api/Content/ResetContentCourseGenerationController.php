<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ResetContentCourseGenerationAction;
use App\Domain\Content\Exceptions\ContentCourseGenerationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\MutateContentCourseGenerationRequest;
use Illuminate\Http\JsonResponse;

final class ResetContentCourseGenerationController extends Controller
{
    public function __invoke(
        MutateContentCourseGenerationRequest $request,
        ResetContentCourseGenerationAction $reset,
        string $courseId,
    ): JsonResponse {
        try {
            $course = $reset->handle(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $courseId,
            );
        } catch (ContentCourseGenerationConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return $course === null
            ? response()->json(['message' => 'Course not found'], 404)
            : response()->json([
                'message' => 'Course reset successfully. You can now start generation again.',
                'courseId' => $course->id,
            ]);
    }
}
