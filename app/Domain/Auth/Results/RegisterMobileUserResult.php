<?php

namespace App\Domain\Auth\Results;

use App\Models\User;
use DateTimeInterface;

final readonly class RegisterMobileUserResult
{
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public ?DateTimeInterface $expiresAt,
    ) {}
}
