<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentGenerationRequest;

final readonly class ReservedContentGenerationRequest
{
    public function __construct(
        public ContentGenerationRequest $request,
        public bool $wasCreated,
    ) {}
}
