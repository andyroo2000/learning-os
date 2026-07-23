<?php

namespace App\Http\Support;

final readonly class ConvoLabContentIdentity
{
    public function __construct(
        public int $userId,
        public ?string $convoLabUserId,
        public ?string $role,
    ) {}
}
