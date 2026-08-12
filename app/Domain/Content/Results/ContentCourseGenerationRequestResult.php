<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentGenerationRequest;

final readonly class ContentCourseGenerationRequestResult
{
    public function __construct(
        public ContentGenerationRequest $request,
        public ?ContentCourseGenerationStartResult $started,
        public ?string $dispatchToken = null,
    ) {}
}
