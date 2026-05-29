<?php

namespace App\Domain\Flashcards\Data;

final readonly class UpdateCardData
{
    private function __construct(
        public string $frontText,
        public string $backText,
    ) {}

    public static function fromInput(
        string $frontText,
        string $backText,
    ): self {
        // Normalize here too so non-HTTP callers get the same domain invariants.
        return new self(
            frontText: trim($frontText),
            backText: trim($backText),
        );
    }
}
