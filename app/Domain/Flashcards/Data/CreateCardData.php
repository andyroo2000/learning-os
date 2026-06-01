<?php

namespace App\Domain\Flashcards\Data;

use App\Support\Identifiers\CanonicalUlid;
use LogicException;

final readonly class CreateCardData
{
    private function __construct(
        public int $userId,
        public string $deckId,
        public string $frontText,
        public string $backText,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        int $userId,
        string $deckId,
        string $frontText,
        string $backText,
        ?string $id = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Card user ID must be a positive integer.');
        }

        return new self(
            userId: $userId,
            // StoreCardRequest normalizes before validation; repeat it here so direct
            // action callers get the same canonical, case-insensitive retry behavior.
            deckId: CanonicalUlid::normalize($deckId),
            frontText: trim($frontText),
            backText: trim($backText),
            id: $id === null ? null : CanonicalUlid::normalize($id),
        );
    }
}
