<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ContentCreationConflictException extends RuntimeException
{
    private function __construct(
        private readonly int $ownerUserId,
        private readonly string $ownerConvoLabUserId,
        private readonly bool $gone,
    ) {
        parent::__construct($gone
            ? 'Creation ID belongs to deleted content.'
            : 'Creation ID was already used for different content.');
    }

    public static function conflict(int $userId, string $convoLabUserId): self
    {
        return new self($userId, $convoLabUserId, false);
    }

    public static function gone(int $userId, string $convoLabUserId): self
    {
        return new self($userId, $convoLabUserId, true);
    }

    public function shouldBeHiddenFrom(int $userId, string $convoLabUserId): bool
    {
        return $this->ownerUserId !== $userId
            || ! hash_equals($this->ownerConvoLabUserId, $convoLabUserId);
    }

    public function isGone(): bool
    {
        return $this->gone;
    }
}
