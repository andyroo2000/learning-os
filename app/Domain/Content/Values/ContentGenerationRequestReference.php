<?php

namespace App\Domain\Content\Values;

final readonly class ContentGenerationRequestReference
{
    public function __construct(
        public ?string $requestId,
        public string $operation,
        public string $jobId,
        public ?int $attempt,
    ) {}
}
