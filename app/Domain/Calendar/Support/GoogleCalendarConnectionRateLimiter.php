<?php

namespace App\Domain\Calendar\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class GoogleCalendarConnectionRateLimiter
{
    public const NAME = 'google-calendar-connection-write';

    public const READ_NAME = 'google-calendar-provider-read';

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

    public function read(Request $request): Limit
    {
        return Limit::perMinute(30)->by(RateLimitKey::scopedUserOrNetwork(
            self::READ_NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    /** @return array{Limit, Limit} */
    public static function oauth(string $operation, Request $request): array
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : '';
        $session = $sessionId === ''
            ? 'missing-session'
            : 'session:'.hash('sha256', $sessionId);
        $ip = $request->ip() ?: 'missing-ip';

        return [
            Limit::perMinute(10)->by($operation.':'.$session),
            Limit::perMinute(60)->by($operation.':network:'.$ip),
        ];
    }
}
