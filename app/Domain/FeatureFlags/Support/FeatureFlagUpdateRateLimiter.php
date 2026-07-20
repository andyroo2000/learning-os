<?php

namespace App\Domain\FeatureFlags\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class FeatureFlagUpdateRateLimiter
{
    public const NAME = 'feature-flag-update';

    public static function limit(Request $request): Limit
    {
        return Limit::perMinute(30)->by(self::keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    public static function keyFor(mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork(self::NAME, $userId, $ip);
    }
}
