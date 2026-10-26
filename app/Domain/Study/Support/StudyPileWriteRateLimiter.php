<?php

namespace App\Domain\Study\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class StudyPileWriteRateLimiter
{
    public const NAME = 'study-pile-write';

    public function limit(Request $request): Limit
    {
        $identity = $request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown';

        return Limit::perMinute(120)->by(self::NAME.':'.$identity);
    }
}
