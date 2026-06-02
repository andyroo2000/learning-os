<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Deck;

final readonly class UpdateDeckResult
{
    private function __construct(
        public Deck $deck,
        public bool $wasUpdated,
    ) {}

    public static function updated(Deck $deck): self
    {
        return new self($deck, true);
    }

    public static function unchanged(Deck $deck): self
    {
        return new self($deck, false);
    }
}
