<?php

namespace App\Domain\Flashcards\Exceptions;

use RuntimeException;

final class CardConflictException extends RuntimeException
{
    // These messages are returned by the card create API and are part of its response contract.
    public const CONFLICT_MESSAGE = 'Card ID already exists with different metadata.';

    public const DELETED_MESSAGE = 'Card ID belongs to a deleted card.';

    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly bool $deleted = false,
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

    public static function deleted(int $conflictingUserId): self
    {
        return new self(
            message: self::DELETED_MESSAGE,
            conflictingUserId: $conflictingUserId,
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
}
