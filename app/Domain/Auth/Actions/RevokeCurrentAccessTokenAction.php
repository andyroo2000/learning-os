<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeCurrentAccessTokenAction
{
    public function handle(User $user): void
    {
        $currentAccessToken = $user->currentAccessToken();

        if ($currentAccessToken instanceof PersonalAccessToken) {
            $currentAccessToken->delete();
        }
    }
}
