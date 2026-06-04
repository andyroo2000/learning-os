<?php

namespace App\Domain\Courses\Data;

use App\Support\Identifiers\CanonicalUlid;

final readonly class CreateCourseData
{
    private function __construct(
        public int $userId,
        public string $title,
        public string $nativeLanguage,
        public string $targetLanguage,
        public ?string $description = null,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        int $userId,
        string $title,
        string $nativeLanguage,
        string $targetLanguage,
        ?string $description = null,
        ?string $id = null,
    ): self {
        return new self(
            userId: $userId,
            title: trim($title),
            nativeLanguage: trim($nativeLanguage),
            targetLanguage: trim($targetLanguage),
            description: $description === null ? null : trim($description),
            id: $id === null ? null : CanonicalUlid::normalize($id),
        );
    }
}
