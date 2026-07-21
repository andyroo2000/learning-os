<?php

namespace App\Domain\Content\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class ContentAudioRateLimiter
{
    public const GENERATION_NAME = 'content-audio-generation';

    public static function generation(Request $request): Limit
    {
        $convoLabUserId = ConvoLabUserId::normalizeOrNull($request->header('X-Convo-Lab-User-Id'));

        return Limit::perMinute(6)->by(RateLimitKey::scopedUserOrNetwork(
            self::GENERATION_NAME,
            $convoLabUserId ?? $request->user()?->getAuthIdentifier(),
            $request->ip(),
        ));
    }
}
