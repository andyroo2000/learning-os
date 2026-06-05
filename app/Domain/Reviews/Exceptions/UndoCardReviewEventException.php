<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class UndoCardReviewEventException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    public static function notLatest(): self
    {
        return new self('Only the latest review for this card can be undone.', 'card_review_event_not_latest', 409);
    }

    public static function missingSnapshot(): self
    {
        return new self('Undo state is missing for this review event.', 'card_review_event_missing_undo_state', 422);
    }

    public static function invalidSnapshot(string $field): self
    {
        return new self("Undo state field [{$field}] is invalid.", 'card_review_event_invalid_undo_state', 422);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
