<?php

namespace App\Domain\Achievements\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class AchievementEvaluationRateLimiter
{
    public const NAME = 'achievement-evaluation';

    // Evaluation is normally one request per dashboard visit or completed study session.
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
