<?php

namespace App\Domain\Auth\Results;

use DateTimeInterface;

final readonly class IssueMobileTokenResult
{
    public function __construct(
        public string $plainTextToken,
        public ?DateTimeInterface $expiresAt,
    ) {}
}
