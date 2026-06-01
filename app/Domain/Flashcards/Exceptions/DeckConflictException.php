<?php

namespace App\Domain\Flashcards\Exceptions;

use App\Domain\Flashcards\Models\Deck;
use RuntimeException;

final class DeckConflictException extends RuntimeException
{
    // Public deck create API response contract; tests assert the literal strings.
    public const CONFLICT_MESSAGE = 'Deck ID already exists with different metadata.';

    public const DELETED_MESSAGE = 'Deck ID belongs to a deleted deck.';

    public const CONFLICT_REASON = 'deck_id_conflict';

    public const DELETED_REASON = 'deck_deleted';

    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly string $reason,
        private readonly bool $deleted = false,
    ) {
        parent::__construct($message);
    }

    public static function conflict(Deck $deck): self
    {
        return new self(
            message: self::CONFLICT_MESSAGE,
            conflictingUserId: $deck->user_id,
            reason: self::CONFLICT_REASON,
        );
    }

    public static function deleted(Deck $deck): self
    {
        return new self(
            message: self::DELETED_MESSAGE,
            conflictingUserId: $deck->user_id,
            reason: self::DELETED_REASON,
            deleted: true,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== $userId;
    }

    public function shouldBeGoneFor(int $userId): bool
    {
        return $this->deleted && ! $this->shouldBeHiddenFrom($userId);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
