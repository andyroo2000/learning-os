<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;

class RevokeAccessTokenAction
{
    public function handle(User $user, int $tokenId): void
    {
        $user->tokens()
            ->whereKey($tokenId)
            ->delete();
    }
}
