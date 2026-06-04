<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class CardReviewEventConflictException extends RuntimeException
{
    private const CONFLICT_MESSAGE = 'Card review event ID already exists with different metadata.';

    private const UNRESOLVED_CONFLICT_MESSAGE = 'Card review event already exists.';

    private const CONFLICT_REASON = 'card_review_event_id_conflict';

    private function __construct(
        string $message,
        private readonly ?int $conflictingUserId,
    ) {
        parent::__construct($message);
    }

    public static function conflict(int $conflictingUserId): self
    {
        return new self(
            message: self::CONFLICT_MESSAGE,
            conflictingUserId: $conflictingUserId,
        );
    }

    public static function unresolvedConflict(): self
    {
        return new self(
            message: self::UNRESOLVED_CONFLICT_MESSAGE,
            conflictingUserId: null,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== null
            && $this->conflictingUserId !== $userId;
    }

    public function reason(): string
    {
        return self::CONFLICT_REASON;
    }
}
