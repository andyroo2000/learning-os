<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class CardReviewEventConflictException extends RuntimeException
{
    private const CONFLICT_MESSAGE = 'Card review event ID already exists with different metadata.';

    private const CONFLICT_REASON = 'card_review_event_id_conflict';

    private function __construct(
        private readonly int $conflictingUserId,
    ) {
        parent::__construct(self::CONFLICT_MESSAGE);
    }

    public static function conflict(int $conflictingUserId): self
    {
        return new self($conflictingUserId);
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== $userId;
    }

    public function reason(): string
    {
        return self::CONFLICT_REASON;
    }
}
