<?php

namespace App\Domain\Flashcards\Exceptions;

use App\Domain\Flashcards\Models\Deck;
use RuntimeException;

final class DeckConflictException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly bool $deleted = false,
    ) {
        parent::__construct($message);
    }

    public static function conflict(Deck $deck): self
    {
        return new self(
            message: 'Deck ID already exists with different metadata.',
            conflictingUserId: $deck->user_id,
        );
    }

    public static function deleted(Deck $deck): self
    {
        return new self(
            message: 'Deck ID belongs to a deleted deck.',
            conflictingUserId: $deck->user_id,
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
}
