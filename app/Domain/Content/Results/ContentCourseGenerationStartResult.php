<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentCourse;

final readonly class ContentCourseGenerationStartResult
{
    public function __construct(
        public ContentCourse $course,
        public int $attempt,
        public bool $audioOnly,
    ) {}
}
