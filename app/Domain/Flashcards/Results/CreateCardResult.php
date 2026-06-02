<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Card;

final readonly class CreateCardResult
{
    private function __construct(
        public Card $card,
        public bool $wasCreated,
    ) {}

    public static function created(Card $card): self
    {
        return new self($card, true);
    }

    public static function existing(Card $card): self
    {
        return new self($card, false);
    }
}
