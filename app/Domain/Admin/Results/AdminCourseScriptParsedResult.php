<?php

namespace App\Domain\Admin\Results;

use App\Domain\Content\Results\ContentCourseScriptUnit;

final readonly class AdminCourseScriptParsedResult
{
    /** @param list<ContentCourseScriptUnit> $units */
    public function __construct(
        public array $units,
        public int $estimatedDurationSeconds,
    ) {}
}
