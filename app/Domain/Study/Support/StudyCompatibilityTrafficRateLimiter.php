<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class StudyCompatibilityTrafficRateLimiter
{
    public const NETWORK_NAME = 'study-compatibility-network';

    public const READ_NAME = 'study-compatibility-read';

    public const MEDIA_NAME = 'study-compatibility-media';

    public static function networkLimit(Request $request): Limit
    {
        return Limit::perMinute(300)->by(self::networkKey($request->ip()));
    }

    public static function readLimit(Request $request): Limit
    {
        return Limit::perMinute(240)->by(self::actorKey(
            self::READ_NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    public static function mediaLimit(Request $request): Limit
    {
        return Limit::perMinute(600)->by(self::actorKey(
            self::MEDIA_NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    /**
     * @internal Exposed for focused limiter tests; route code should use the named limiters.
     */
    public static function networkKey(?string $ip): string
    {
        return self::NETWORK_NAME.':network:'.($ip !== null && $ip !== '' ? $ip : 'unknown-ip');
    }

    /**
     * @internal Exposed for focused limiter tests; route code should use the named limiters.
     */
    public static function actorKey(string $scope, mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork($scope, $userId, $ip);
    }
}
