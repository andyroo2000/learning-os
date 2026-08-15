<?php

namespace App\Domain\Calendar\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class GoogleCalendarConnectionRateLimiter
{
    public const NAME = 'google-calendar-connection-write';

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(10)->by(RateLimitKey::scopedUserOrNetwork(
            self::NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
