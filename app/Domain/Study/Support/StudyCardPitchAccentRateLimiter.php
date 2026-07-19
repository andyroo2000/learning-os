<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardPitchAccentRateLimiter
{
    public const NAME = 'study-card-pitch-accent';

    private const PER_MINUTE = 60;

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(self::PER_MINUTE)->by(RateLimitKey::scopedUserOrNetwork(
            self::NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
