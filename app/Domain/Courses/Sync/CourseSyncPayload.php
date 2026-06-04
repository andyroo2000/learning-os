<?php

namespace App\Domain\Courses\Sync;

use App\Domain\Courses\Models\Course;

final class CourseSyncPayload
{
    public const DOMAIN = 'courses';

    public const RESOURCE_TYPE = 'course';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'status' => $course->status?->value,
            'native_language' => $course->native_language,
            'target_language' => $course->target_language,
            'created_at' => $course->created_at?->toJSON(),
            'updated_at' => $course->updated_at?->toJSON(),
            'deleted_at' => $course->deleted_at?->toJSON(),
        ];
    }
}
