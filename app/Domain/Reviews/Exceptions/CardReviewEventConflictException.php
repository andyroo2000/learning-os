<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class CardReviewEventConflictException extends RuntimeException
{
    private const CONFLICT_MESSAGE = 'Card review event ID already exists with different metadata.';

    private const CONFLICT_REASON = 'card_review_event_id_conflict';

    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function conflict(int $conflictingUserId): self
    {
        return new self(
            message: self::CONFLICT_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::CONFLICT_REASON,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== $userId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
