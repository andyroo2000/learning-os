<?php

namespace App\Domain\Flashcards\Data;

use App\Support\Identifiers\CanonicalUlid;

final readonly class CreateDeckData
{
    private function __construct(
        public int $userId,
        public string $name,
        public ?string $description = null,
        public ?string $courseId = null,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        int $userId,
        string $name,
        ?string $description = null,
        ?string $courseId = null,
        ?string $id = null,
    ): self {
        return new self(
            userId: $userId,
            name: trim($name),
            description: $description === null ? null : trim($description),
            courseId: $courseId === null ? null : CanonicalUlid::normalize($courseId),
            id: $id === null ? null : CanonicalUlid::normalize($id),
        );
    }
}
