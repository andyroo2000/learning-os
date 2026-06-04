<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Actions\DeleteCourseAction;
use App\Domain\Courses\Models\Course;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class DeleteCourseController extends Controller
{
    public function __invoke(string $course, DeleteCourseAction $deleteCourse): Response
    {
        // Bypass route model binding intentionally: normal binding excludes trashed
        // courses, but DELETE retries for owned soft-deleted courses should stay idempotent.
        $courseModel = Course::withTrashed()->findOrFail($course);

        $this->authorize('delete', $courseModel);

        $deleteCourse->handle($courseModel);

        return response()->noContent();
    }
}
