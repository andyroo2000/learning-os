<?php

namespace App\Domain\Calendar\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class GoogleCalendarConnectionRateLimiter
{
    public const NAME = 'google-calendar-connection-write';

    public const OAUTH_BEGIN = 'google-calendar-oauth-begin';

    public const OAUTH_CALLBACK = 'google-calendar-oauth-callback';

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(10)->by(RateLimitKey::scopedUserOrNetwork(
            self::NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    /** @return array{Limit, Limit} */
    public static function oauth(string $operation, Request $request): array
    {
        $session = $request->hasSession() ? $request->session()->getId() : '';
        $ip = $request->ip() ?: 'missing-ip';

        return [
            Limit::perMinute(10)->by($operation.':session:'.hash('sha256', $session)),
            Limit::perMinute(60)->by($operation.':network:'.$ip),
        ];
    }
}
