<?php

namespace App\Http\Resources\Courses;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
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

        // Keep the normal resource shape aligned with the sync snapshot, including future tombstones.
        return CourseSyncPayload::fromCourse($course);
    }
}
