<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class CardReviewEventConflictException extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 1;

    private const CONFLICT_MESSAGE = 'Card review event ID already exists with different metadata.';

    private const RETRYABLE_MESSAGE = 'Card review event ID conflict could not be resolved; retry the request.';

    private const CONFLICT_REASON = 'card_review_event_id_conflict';

    private const RETRYABLE_REASON = 'card_review_event_retry';

    private function __construct(
        string $message,
        private readonly ?int $conflictingUserId,
        private readonly string $reason,
        private readonly bool $retryable = false,
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

    public static function retryableConflict(): self
    {
        return new self(
            message: self::RETRYABLE_MESSAGE,
            conflictingUserId: null,
            reason: self::RETRYABLE_REASON,
            retryable: true,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== null
            && $this->conflictingUserId !== $userId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
