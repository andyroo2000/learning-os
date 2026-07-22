<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class ConvoLabOAuthException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function identityNotFound(): self
    {
        return new self('No Google account connected', 'identity_not_found', 404);
    }

    public static function inviteAlreadyClaimed(): self
    {
        return new self('An invite code has already been claimed', 'invite_already_claimed', 409);
    }

    public static function identityAlreadyConnected(): self
    {
        return new self(
            'A different Google account is already connected',
            'identity_already_connected',
            409,
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return $this->status;
    }
}
