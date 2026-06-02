<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Card;

final readonly class UpdateCardResult
{
    private function __construct(
        public Card $card,
        public bool $wasUpdated,
    ) {}

    public static function updated(Card $card): self
    {
        return new self($card, true);
    }

    public static function unchanged(Card $card): self
    {
        return new self($card, false);
    }
}
