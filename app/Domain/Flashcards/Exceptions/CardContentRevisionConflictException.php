<?php

namespace App\Domain\Flashcards\Exceptions;

use App\Domain\Flashcards\Models\Card;
use RuntimeException;

final class CardContentRevisionConflictException extends RuntimeException
{
    public const CODE = 'card_revision_conflict';

    public const MESSAGE = 'Study card content changed since it was loaded.';

    public function __construct(public readonly Card $card)
    {
        parent::__construct(self::MESSAGE);
    }
}
