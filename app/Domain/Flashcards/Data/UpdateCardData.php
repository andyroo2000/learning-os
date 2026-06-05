<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Enums\CardType;

final readonly class UpdateCardData
{
    private function __construct(
        public string $frontText,
        public string $backText,
        public ?CardType $cardType,
    ) {}

    public static function fromInput(
        string $frontText,
        string $backText,
        CardType|string|null $cardType = null,
    ): self {
        // Normalize here too so non-HTTP callers get the same domain invariants.
        return new self(
            frontText: trim($frontText),
            backText: trim($backText),
            cardType: $cardType === null ? null : CardType::fromInput($cardType),
        );
    }
}
