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

        // Auth middleware should have already resolved the application user; any other
        // value here is a route wiring error, not another authentication challenge.
        if (! $user instanceof User) {
            throw new LogicException('Authenticated request user must be an application user.');
        }

        $userId = $user->getRawOriginal($user->getKeyName());

        if ($userId === null) {
            throw new LogicException('Authenticated user ID must be set.');
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
