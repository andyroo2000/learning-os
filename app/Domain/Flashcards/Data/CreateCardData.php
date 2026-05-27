<?php

namespace App\Domain\Flashcards\Data;

final readonly class CreateCardData
{
    private function __construct(
        public string $deckId,
        public string $frontText,
        public string $backText,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        string $deckId,
        string $frontText,
        string $backText,
        ?string $id = null,
    ): self {
        return new self(
            deckId: trim($deckId),
            frontText: trim($frontText),
            backText: trim($backText),
            id: $id === null ? null : trim($id),
        );
    }
}
