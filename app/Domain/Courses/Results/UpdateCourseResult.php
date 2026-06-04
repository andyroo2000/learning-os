<?php

namespace App\Domain\Courses\Results;

use App\Domain\Courses\Models\Course;

final readonly class UpdateCourseResult
{
    private function __construct(
        public Course $course,
        public bool $wasUpdated,
    ) {}

    public static function updated(Course $course): self
    {
        return new self($course, true);
    }

    public static function unchanged(Course $course): self
    {
        return new self($course, false);
    }
}
