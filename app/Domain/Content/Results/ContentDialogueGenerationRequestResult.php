<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentGenerationRequest;

final readonly class ContentDialogueGenerationRequestResult
{
    public function __construct(
        public ContentGenerationRequest $request,
        public ?ContentDialogueGenerationJob $job,
        public ?string $dispatchToken = null,
    ) {}
}
