<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\DeleteContentCourseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\DeleteContentCourseRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class DeleteContentCourseController extends Controller
{
    public function __invoke(
        DeleteContentCourseRequest $request,
        string $courseId,
        DeleteContentCourseAction $action,
    ): JsonResponse {
        if (! $action->handle(
            AuthenticatedUser::id($request),
            $request->convoLabUserId(),
            $courseId,
        )) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        return response()->json(['message' => 'Course deleted successfully']);
    }
}
