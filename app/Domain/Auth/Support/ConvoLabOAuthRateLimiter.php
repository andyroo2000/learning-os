<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ConvoLabOAuthRateLimiter
{
    public const RESOLVE = 'convolab-oauth-resolve';

    public const CLAIM = 'convolab-oauth-claim';

    public const DISCONNECT = 'convolab-oauth-disconnect';

    public static function resolve(Request $request): array
    {
        $email = $request->input('email');
        $email = is_string($email) ? Str::lower(trim($email)) : '';

        return self::limits(
            self::RESOLVE,
            $email === '' ? 'missing-email' : 'email:'.$email,
            $request->ip(),
            12,
        );
    }

    public static function authenticated(string $operation, Request $request): array
    {
        $userId = $request->header('X-Convo-Lab-User-Id');
        $userId = is_string($userId) ? Str::lower(trim($userId)) : '';
        $ip = $request->ip();
        $network = $ip === null || $ip === '' ? 'missing-ip' : 'ip:'.$ip;
        $identity = $userId === '' ? 'anon:'.$network : 'user:'.$userId;

        return [
            Limit::perMinute(5)->by($operation.'|'.$identity),
            Limit::perMinute(60)->by($operation.'-network|'.$network),
        ];
    }

    private static function limits(
        string $operation,
        string $identity,
        ?string $ip,
        int $identityPerMinute,
    ): array {
        $network = $ip === null || $ip === '' ? 'missing-ip' : 'ip:'.$ip;

        return [
            Limit::perMinute($identityPerMinute)->by($operation.'|'.$identity.'|'.$network),
            Limit::perMinute(60)->by($operation.'-network|'.$network),
        ];
    }
}
