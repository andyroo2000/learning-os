<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Actions\UpdateCourseAction;
use App\Domain\Courses\Data\UpdateCourseData;
use App\Domain\Courses\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\UpdateCourseRequest;
use App\Http\Resources\Courses\CourseResource;
use Illuminate\Http\JsonResponse;

class UpdateCourseController extends Controller
{
    public function __invoke(UpdateCourseRequest $request, Course $course, UpdateCourseAction $updateCourse): JsonResponse
    {
        $result = $updateCourse->handle($course, UpdateCourseData::fromInput(
            title: $request->validated('title'),
            description: $request->validated('description'),
        ));

        return CourseResource::make($result->course)
            ->response();
    }
}
