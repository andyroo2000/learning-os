<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyMediaGenerationRateLimiter
{
    public const NAME = 'study-media-generation';

    private const PER_MINUTE = 10;

    public function limit(Request $request, int $perMinute = self::PER_MINUTE): Limit
    {
        return Limit::perMinute($perMinute)->by($this->keyFor(
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }

    public function keyFor(mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork(self::NAME, $userId, $ip);
    }
}
