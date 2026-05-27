<?php

namespace App\Domain\Flashcards\Data;

final readonly class CreateDeckData
{
    private function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        string $name,
        ?string $description = null,
        ?string $id = null,
    ): self {
        return new self(
            name: trim($name),
            description: $description === null ? null : trim($description),
            id: $id === null ? null : trim($id),
        );
    }
}
