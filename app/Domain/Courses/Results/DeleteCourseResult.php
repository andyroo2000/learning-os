<?php

namespace App\Domain\Courses\Results;

use App\Domain\Courses\Models\Course;

final readonly class DeleteCourseResult
{
    private function __construct(
        public Course $course,
        public bool $wasDeleted,
    ) {}

    public static function deleted(Course $course): self
    {
        return new self($course, true);
    }

    public static function unchanged(Course $course): self
    {
        return new self($course, false);
    }
}
