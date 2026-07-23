<?php

namespace App\Domain\Content\Support;

use App\Http\Support\ConvoLabRequestIdentity;
use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ContentImageRateLimiter
{
    public const GENERATION_NAME = 'content-image-generation';

    public static function generation(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull(
            ConvoLabRequestIdentity::userId($request),
        );

        return Limit::perMinute(10)->by(RateLimitKey::scopedUserOrNetwork(
            self::GENERATION_NAME,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
