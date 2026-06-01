<?php

namespace App\Domain\Flashcards\Exceptions;

use RuntimeException;

final class CardConflictException extends RuntimeException
{
    // These messages are returned by the card create API and are part of its response contract.
    public const CONFLICT_MESSAGE = 'Card ID already exists with different metadata.';

    public const CARD_DELETED_MESSAGE = 'Card ID belongs to a deleted card.';

    public const DECK_DELETED_MESSAGE = 'Card ID belongs to a deleted deck.';

    public const CONFLICT_REASON = 'card_id_conflict';

    public const CARD_DELETED_REASON = 'card_deleted';

    public const DECK_DELETED_REASON = 'deck_deleted';

    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly string $reason,
        private readonly bool $deleted = false,
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

    public static function cardDeleted(int $conflictingUserId): self
    {
        return new self(
            message: self::CARD_DELETED_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::CARD_DELETED_REASON,
            deleted: true,
        );
    }

    public static function deckDeleted(int $conflictingUserId): self
    {
        return new self(
            message: self::DECK_DELETED_MESSAGE,
            conflictingUserId: $conflictingUserId,
            reason: self::DECK_DELETED_REASON,
            deleted: true,
        );
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->conflictingUserId === $userId;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
