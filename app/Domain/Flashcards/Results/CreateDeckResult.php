<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Deck;

final readonly class CreateDeckResult
{
    private function __construct(
        public Deck $deck,
        public bool $wasCreated,
    ) {}

    public static function created(Deck $deck): self
    {
        return new self($deck, true);
    }

    public static function existing(Deck $deck): self
    {
        return new self($deck, false);
    }
}
