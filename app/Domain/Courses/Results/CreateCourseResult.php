<?php

namespace App\Domain\Courses\Results;

use App\Domain\Courses\Models\Course;

final readonly class CreateCourseResult
{
    private function __construct(
        public Course $course,
        public bool $wasCreated,
    ) {}

    public static function created(Course $course): self
    {
        return new self($course, true);
    }

    public static function existing(Course $course): self
    {
        return new self($course, false);
    }
}
