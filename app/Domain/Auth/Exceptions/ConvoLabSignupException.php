<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class ConvoLabSignupException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidInvite(): self
    {
        return new self('Invalid invite code', 'invalid_invite', 400);
    }

    public static function usedInvite(): self
    {
        return new self('This invite code has already been used', 'used_invite', 400);
    }

    public static function accountExists(): self
    {
        return new self('An account with this email already exists', 'account_exists', 400);
    }

    public static function invalidRetryCredentials(): self
    {
        return new self('Invalid credentials.', 'invalid_credentials', 401);
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
