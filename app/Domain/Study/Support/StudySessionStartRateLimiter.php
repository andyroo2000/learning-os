<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class StudySessionStartRateLimiter
{
    public const NAME = 'study-session-start';

    // Session start is read-like but query-heavy; 60/min allows app reloads without leaving it unbounded.
    private const PER_MINUTE = 60;

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(self::PER_MINUTE)->by($this->key($request));
    }

    private function key(Request $request): string
    {
        return $this->keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        );
    }

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
    public function keyFor(int|string|null $userId, ?string $ip): string
    {
        // App user IDs are positive integers; zero-like identifiers stay on the defensive network fallback.
        if ((is_int($userId) && $userId > 0) || (is_string($userId) && $userId !== '' && $userId !== '0')) {
            return self::NAME.':user:'.(string) $userId;
        }

        $network = $ip !== null && $ip !== '' ? $ip : 'unknown-ip';

        return self::NAME.':anon:'.$network;
    }
}
