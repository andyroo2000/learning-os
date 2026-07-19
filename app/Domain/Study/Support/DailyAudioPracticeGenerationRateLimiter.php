<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class DailyAudioPracticeGenerationRateLimiter
{
    public const NAME = 'daily-audio-practice-generation';

    private const PER_HOUR = 10;

    public function limit(Request $request): Limit
    {
        return Limit::perHour(self::PER_HOUR)->by($this->keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
    public function keyFor(int|string|null $userId, ?string $ip): string
    {
        if ((is_int($userId) && $userId > 0) || (is_string($userId) && $userId !== '' && $userId !== '0')) {
            return self::NAME.':user:'.(string) $userId;
        }

        return self::NAME.':anon:'.($ip !== null && $ip !== '' ? $ip : 'unknown-ip');
    }
}
