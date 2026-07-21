<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\UpdateContentCourseAction;
use App\Domain\Content\Data\UpdateContentCourseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpdateContentCourseRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class UpdateContentCourseController extends Controller
{
    public function __invoke(
        UpdateContentCourseRequest $request,
        string $courseId,
        UpdateContentCourseAction $action,
    ): JsonResponse {
        $updated = $action->handle(
            AuthenticatedUser::id($request),
            $request->convoLabUserId(),
            $courseId,
            UpdateContentCourseData::fromInput($request->validated()),
        );

        if (! $updated) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        return response()->json(['message' => 'Course updated']);
    }
}
