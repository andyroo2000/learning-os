<?php

namespace App\Http\Support;

use App\Models\User;
use Illuminate\Http\Request;
use LogicException;

final class AuthenticatedUser
{
    public static function id(Request $request): int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Authenticated request user must be an application user.');
        }

        $userId = $user->getRawOriginal($user->getKeyName());

        if ($userId === null && $user->getAttribute($user->getKeyName()) !== null) {
            $userId = $user->getAttribute($user->getKeyName());
        }

        if (is_string($userId) && ! ctype_digit($userId)) {
            throw new LogicException('Authenticated user ID must be a positive integer.');
        }

        $resolvedUserId = (int) $userId;

        if ($resolvedUserId <= 0) {
            throw new LogicException('Authenticated user ID must be a positive integer.');
        }

        return $resolvedUserId;
    }
}
