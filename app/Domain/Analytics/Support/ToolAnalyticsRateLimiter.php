<?php

namespace App\Domain\Analytics\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ToolAnalyticsRateLimiter
{
    public const NAME = 'tool-analytics-store';

    public static function limit(Request $request): Limit
    {
        // This is aggregate proxy traffic, so leave room for many browser sessions.
        return Limit::perMinute(6000)->by(self::keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    public static function keyFor(mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork(self::NAME, $userId, $ip);
    }
}
