<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Models\Deck;
use App\Support\Identifiers\CanonicalUlid;
use InvalidArgumentException;

final readonly class CreateDeckData
{
    private function __construct(
        public int $userId,
        public string $name,
        public ?string $description = null,
        public ?string $courseId = null,
        public ?string $id = null,
        public bool $isManualStudyDeck = false,
    ) {}

    public static function fromInput(
        int $userId,
        string $name,
        ?string $description = null,
        ?string $courseId = null,
        ?string $id = null,
        bool $isManualStudyDeck = false,
    ): self {
        $name = trim($name);

        if (mb_strlen($name) > Deck::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Deck name must not exceed '.Deck::MAX_NAME_LENGTH.' characters.');
        }

        return new self(
            userId: $userId,
            name: $name,
            description: $description === null ? null : trim($description),
            courseId: $courseId === null ? null : CanonicalUlid::normalize($courseId),
            id: $id === null ? null : CanonicalUlid::normalize($id),
            isManualStudyDeck: $isManualStudyDeck,
        );
    }
}
