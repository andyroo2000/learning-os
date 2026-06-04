<?php

namespace App\Domain\Flashcards\Exceptions;

use InvalidArgumentException;

final class CardValidationException extends InvalidArgumentException
{
    public const FIELD_ID = 'id';

    public const FIELD_DECK_ID = 'deck_id';

    public const FIELD_FRONT_TEXT = 'front_text';

    public const FIELD_BACK_TEXT = 'back_text';

    public const FIELD_CARD_IDS = 'card_ids';

    public static function invalidDeckId(): self
    {
        return new self(self::FIELD_DECK_ID, 'Deck ID must be a valid ULID.');
    }

    public static function deckDoesNotExist(): self
    {
        return new self(self::FIELD_DECK_ID, 'Deck does not exist.');
    }

    public static function missingFrontText(): self
    {
        return new self(self::FIELD_FRONT_TEXT, 'Card front text is required.');
    }

    public static function missingBackText(): self
    {
        return new self(self::FIELD_BACK_TEXT, 'Card back text is required.');
    }

    public static function invalidCardId(): self
    {
        return new self(self::FIELD_ID, 'Card ID must be a valid ULID.');
    }

    public static function invalidCardIds(string $message): self
    {
        return new self(self::FIELD_CARD_IDS, $message);
    }

    private function __construct(private readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
