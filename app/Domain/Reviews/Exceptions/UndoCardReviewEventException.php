<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class UndoCardReviewEventException extends RuntimeException
{
    public const CARD_UNAVAILABLE = 'card_review_event_card_unavailable';

    public const NOT_LATEST = 'card_review_event_not_latest';

    public const MISSING_SNAPSHOT = 'card_review_event_missing_undo_state';

    public const INVALID_SNAPSHOT = 'card_review_event_invalid_undo_state';

    private function __construct(
        string $message,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function cardUnavailable(): self
    {
        return new self('Review event card is not available for undo.', self::CARD_UNAVAILABLE);
    }

    public static function notLatest(): self
    {
        return new self('Only the latest review for this card can be undone.', self::NOT_LATEST);
    }

    public static function missingSnapshot(): self
    {
        return new self('Undo state is missing for this review event.', self::MISSING_SNAPSHOT);
    }

    public static function invalidSnapshot(string $field): self
    {
        return new self("Undo state field [{$field}] is invalid.", self::INVALID_SNAPSHOT);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
