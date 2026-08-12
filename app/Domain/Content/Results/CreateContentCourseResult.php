<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentCourse;

final readonly class CreateContentCourseResult
{
    private function __construct(
        public ?ContentCourse $course,
        public bool $episodesFound,
        public bool $wasCreated,
    ) {}

    public static function created(ContentCourse $course): self
    {
        return new self($course, true, true);
    }

    public static function existing(ContentCourse $course): self
    {
        return new self($course, true, false);
    }

    public static function episodesNotFound(): self
    {
        return new self(null, false, false);
    }
}
