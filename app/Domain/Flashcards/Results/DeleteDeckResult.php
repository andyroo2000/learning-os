<?php

namespace App\Domain\Flashcards\Results;

use App\Domain\Flashcards\Models\Deck;

final readonly class DeleteDeckResult
{
    private function __construct(
        public Deck $deck,
        public bool $wasDeleted,
    ) {}

    public static function deleted(Deck $deck): self
    {
        return new self($deck, true);
    }

    public static function unchanged(Deck $deck): self
    {
        return new self($deck, false);
    }
}
