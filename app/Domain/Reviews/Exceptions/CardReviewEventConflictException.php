<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

final class CardReviewEventConflictException extends RuntimeException
{
    public const RETRY_AFTER_SECONDS = 1;

    private const CONFLICT_MESSAGE = 'Card review event ID already exists with different metadata.';

    private const RETRYABLE_MESSAGE = 'Card review event ID conflict could not be resolved; retry the request.';

    private const OUT_OF_ORDER_MESSAGE = 'Review events must be submitted after the card\'s latest review, ordered by reviewed_at and id.';

    private const IDENTITY_REQUIRED_MESSAGE = 'Equal-timestamp review events require an explicit id or complete sync metadata.';

    private const PROGRESSION_LOCKED_MESSAGE = 'Card is locked by a learning progression.';

    private const CONFLICT_REASON = 'card_review_event_id_conflict';

    private const RETRYABLE_REASON = 'card_review_event_retry';

    private const OUT_OF_ORDER_REASON = 'card_review_event_out_of_order';

    private const IDENTITY_REQUIRED_REASON = 'card_review_event_identity_required';

    private const PROGRESSION_LOCKED_REASON = 'card_progression_locked';

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

    public static function outOfOrder(int $conflictingUserId): self
    {
        return new self(
            message: self::OUT_OF_ORDER_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::OUT_OF_ORDER_REASON,
        );
    }

    public static function identityRequired(int $conflictingUserId): self
    {
        return new self(
            message: self::IDENTITY_REQUIRED_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::IDENTITY_REQUIRED_REASON,
        );
    }

    public static function progressionLocked(int $conflictingUserId): self
    {
        return new self(
            message: self::PROGRESSION_LOCKED_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::PROGRESSION_LOCKED_REASON,
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
