<?php

namespace App\Domain\Auth\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ConvoLabProfileRateLimiter
{
    public const NAME = 'convolab-profile-update';

    public const NETWORK_NAME = 'convolab-profile-update-network';

    public static function limit(Request $request): Limit
    {
        return Limit::perMinute(30)->by(self::key(
            self::NAME,
            $request->header('X-Convo-Lab-User-Id'),
            $request->ip(),
        ));
    }

    public static function networkLimit(Request $request): Limit
    {
        return Limit::perMinute(120)->by(self::key(self::NETWORK_NAME, null, $request->ip()));
    }

    public static function key(string $name, mixed $identity, ?string $ip): string
    {
        $identity = is_string($identity) ? strtolower(trim($identity)) : '';
        $identityKey = $identity === '' ? 'missing' : hash('sha256', $identity);
        $networkKey = $ip === null || $ip === '' ? 'unknown-ip' : $ip;

        return $name.':'.$identityKey.':'.$networkKey;
    }
}
