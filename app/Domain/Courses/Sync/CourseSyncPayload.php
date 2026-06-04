<?php

namespace App\Domain\Courses\Sync;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Snapshots\CourseSnapshot;

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
        return CourseSnapshot::fromCourse($course);
    }
}
