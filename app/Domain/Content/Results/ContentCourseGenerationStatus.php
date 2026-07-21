<?php

namespace App\Domain\Content\Results;

final readonly class ContentCourseGenerationStatus
{
    public function __construct(
        public string $status,
        public ?int $progress,
        public bool $isStuck,
    ) {}

    /** @return array{status: string, progress: int|null, isStuck: bool} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'progress' => $this->progress,
            'isStuck' => $this->isStuck,
        ];
    }
}
