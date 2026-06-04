<?php

namespace App\Http\Resources\Courses;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Snapshots\CourseSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Course $course */
        $course = $this->resource;

        return CourseSnapshot::fromCourse($course);
    }
}
