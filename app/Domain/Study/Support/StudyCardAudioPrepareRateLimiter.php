<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class StudyCardAudioPrepareRateLimiter
{
    public const NAME = 'study-card-audio-prepare';

    private const PER_MINUTE = 30;

    public function limit(Request $request): Limit
    {
        return Limit::perMinute(self::PER_MINUTE)->by(RateLimitKey::scopedUserOrNetwork(
            self::NAME,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
