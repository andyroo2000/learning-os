<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ConvoLabAccountSecurityRateLimiter
{
    public const PASSWORD_UPDATE = 'convolab-account-password-update';

    public const ACCOUNT_DELETE = 'convolab-account-delete';

    public const NETWORK_SUFFIX = '-network';

    public static function limits(string $operation, Request $request): array
    {
        return [
            Limit::perMinute(5)->by(ConvoLabProfileRateLimiter::key(
                $operation,
                $request->header('X-Convo-Lab-User-Id'),
                null,
            )),
            Limit::perMinute(60)->by(ConvoLabProfileRateLimiter::key(
                $operation.self::NETWORK_SUFFIX,
                null,
                $request->ip(),
            )),
        ];
    }
}
