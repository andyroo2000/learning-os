<?php

namespace App\Domain\Flashcards\Support;

use App\Domain\Flashcards\Exceptions\CardContentRevisionConflictException;
use App\Domain\Flashcards\Models\Card;
use LogicException;

final class CardContentRevision
{
    private function __construct() {}

    public static function assertExpected(Card $card, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $card->content_revision !== $expectedRevision) {
            throw new CardContentRevisionConflictException($card);
        }
    }

    public static function advance(Card $card): void
    {
        if ($card->content_revision >= PHP_INT_MAX) {
            throw new LogicException('Card content revision cannot be advanced beyond the platform integer limit.');
        }

        $card->content_revision++;
    }
}
