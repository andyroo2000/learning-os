<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Card;

final readonly class DeleteCardResult
{
    private function __construct(
        public Card $card,
        public bool $wasDeleted,
    ) {}

    public static function deleted(Card $card): self
    {
        return new self($card, true);
    }

    public static function unchanged(Card $card): self
    {
        return new self($card, false);
    }
}
