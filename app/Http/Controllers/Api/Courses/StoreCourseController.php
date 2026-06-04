<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Actions\CreateCourseAction;
use App\Domain\Courses\Data\CreateCourseData;
use App\Domain\Courses\Exceptions\CourseConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\StoreCourseRequest;
use App\Http\Resources\Courses\CourseResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StoreCourseController extends Controller
{
    public function __invoke(StoreCourseRequest $request, CreateCourseAction $createCourse): JsonResponse
    {
        $data = $request->validated();
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userId = $user->id;

        try {
            $result = $createCourse->handle(CreateCourseData::fromInput(
                userId: $userId,
                title: $data['title'],
                nativeLanguage: $data['native_language'],
                targetLanguage: $data['target_language'],
                description: $data['description'] ?? null,
                id: $data['id'] ?? null,
            ));
        } catch (CourseConflictException $exception) {
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            if ($exception->shouldBeGoneFor($userId)) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'reason' => $exception->reason(),
                ], 410);
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        }

        return CourseResource::make($result->course)
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }
}
