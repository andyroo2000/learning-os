<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Resources\Courses\CourseResource;

class ShowCourseController extends Controller
{
    public function __invoke(Course $course): CourseResource
    {
        $this->authorize('view', $course);

        return CourseResource::make($course);
    }
}
